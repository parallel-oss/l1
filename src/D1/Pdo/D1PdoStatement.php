<?php

namespace Parallel\L1\D1\Pdo;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Arr;
use PDO;
use PDOException;
use PDOStatement;
use Saloon\Http\Response;
use Stringable;
use Throwable;

class D1PdoStatement extends PDOStatement
{
    protected const MAX_RETRIES = 2;

    protected const RETRYABLE_HTTP_STATUSES = [408, 409, 425, 429, 500, 502, 503, 504];

    /**
     * Retry is conservative by default: safe reads + explicitly idempotent DDL patterns.
     */
    protected const RETRYABLE_QUERY_PATTERNS = [
        '/^\s*(select|explain|with)\b/is',
        '/^\s*create\s+table\s+if\s+not\s+exists\b/is',
        '/^\s*create\s+(unique\s+)?index\s+if\s+not\s+exists\b/is',
        '/^\s*drop\s+table\s+if\s+exists\b/is',
        '/^\s*drop\s+index\s+if\s+exists\b/is',
    ];

    protected const RETRYABLE_ERROR_FRAGMENTS = [
        'network connection lost',
        'storage caused object to be reset',
        'reset because its code was updated',
        'cannot resolve d1 db due to transient issue',
        'd1 db is overloaded',
        'requests queued for too long',
        'too many requests queued',
        'temporarily unavailable',
        'database is locked',
        'timeout',
    ];

    protected int $fetchMode = PDO::FETCH_ASSOC;
    protected array $bindings = [];
    protected array $responses = [];
    protected int $rowIndex = 0;
    protected array $rows = [];
    /** @var int|null Rows affected (from D1 meta.changes) for write statements */
    protected ?int $changes = null;

    public function __construct(
        protected D1Pdo &$pdo,
        protected string $query,
        protected array $options = [],
    ) {
        //
    }

    #[\ReturnTypeWillChange]
    public function setFetchMode(int $mode, mixed ...$args)
    {
        $this->fetchMode = $mode;

        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = match ($type) {
            PDO::PARAM_STR => (string) $value,
            PDO::PARAM_BOOL => (bool) $value,
            PDO::PARAM_INT => (int) $value,
            PDO::PARAM_NULL => null,
            default => $value,
        };

        return true;
    }

    public function execute(?array $params = null): bool
    {
        $bindings = $this->bindings !== [] ? $this->bindings : ($params ?? []);
        $this->bindings = array_map(
            fn (mixed $binding) => $this->normalizeBinding($binding),
            array_values($bindings)
        );

        [$response, $payload] = $this->executeWithRetries();

        if (! is_array($payload)) {
            throw $this->buildPdoExceptionFromResponse($response, $payload);
        }

        if ($response->failed() || ! Arr::get($payload, 'success', false)) {
            throw $this->buildPdoExceptionFromResponse($response, $payload);
        }

        $this->responses = Arr::get($payload, 'result', []);
        $this->rows = $this->rowsFromResponses();
        $this->rowIndex = 0;
        $lastResult = Arr::last($this->responses);
        $this->changes = null;
        if (count($this->rows) === 0 && $lastResult && isset($lastResult['meta']['changes'])) {
            $this->changes = (int) $lastResult['meta']['changes'];
        }

        $lastId = Arr::get($lastResult, 'meta.last_row_id', null);

        if (! in_array($lastId, [0, null])) {
            $this->pdo->setLastInsertId(value: $lastId);
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->rowIndex >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->rowIndex++];
        return match ($this->fetchMode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_OBJ => (object) $row,
            default => throw new PDOException('Unsupported fetch mode.'),
        };
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $response = match ($this->fetchMode) {
            PDO::FETCH_ASSOC => $this->rows,
            PDO::FETCH_OBJ => collect($this->rows)->map(fn ($row) => (object) $row)->toArray(),
            default => throw new PDOException('Unsupported fetch mode.'),
        };

        return $response;
    }

    public function rowCount(): int
    {
        if ($this->changes !== null) {
            return $this->changes;
        }
        return count($this->rows);
    }

    protected function rowsFromResponses(): array
    {
        return collect($this->responses)
            ->map(fn ($response) => $response['results'] ?? [])
            ->collapse()
            ->toArray();
    }

    /**
     * @return array{0: Response, 1: array<array-key, mixed>|null}
     */
    protected function executeWithRetries(): array
    {
        $attempt = 0;
        $maxAttempts = static::MAX_RETRIES + 1;
        $isRetryEligibleQuery = $this->isRetryEligibleQuery($this->query);

        do {
            $response = $this->pdo->d1()->databaseQuery(
                $this->query,
                $this->bindings,
            );

            $payload = $this->decodeJsonSafely($response);

            if ($payload !== null && ! $response->failed() && Arr::get($payload, 'success', false)) {
                return [$response, $payload];
            }

            $attempt++;
            if ($attempt >= $maxAttempts || ! $isRetryEligibleQuery || ! $this->shouldRetry($response, $payload)) {
                return [$response, $payload];
            }

            // Small exponential backoff for transient Cloudflare API failures.
            usleep(50000 * (2 ** ($attempt - 1)));
        } while (true);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected function decodeJsonSafely(Response $response): ?array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    protected function shouldRetry(Response $response, ?array $payload): bool
    {
        $status = $response->status();

        if (in_array($status, static::RETRYABLE_HTTP_STATUSES, true) || $status >= 520) {
            return true;
        }

        $message = strtolower((string) Arr::get($payload, 'errors.0.message', ''));
        if ($message === '') {
            return false;
        }

        foreach (static::RETRYABLE_ERROR_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    protected function buildTransportErrorMessage(Response $response): string
    {
        $body = trim($response->body());
        $snippet = $body === '' ? '(empty response body)' : substr($body, 0, 500);

        return sprintf(
            'D1 transport error (HTTP %d): %s',
            $response->status(),
            $snippet,
        );
    }

    protected function buildPdoExceptionFromResponse(Response $response, ?array $payload): PDOException
    {
        $message = trim((string) Arr::get($payload, 'errors.0.message', ''));
        $rawCode = Arr::get($payload, 'errors.0.code', $response->status());
        $code = is_numeric($rawCode) ? (int) $rawCode : 0;

        if ($message === '') {
            $message = $this->buildTransportErrorMessage($response);
            if ($code === 0) {
                $code = $response->status();
            }
        }

        if (stripos($message, 'UNIQUE constraint') !== false || stripos($message, 'SQLITE_CONSTRAINT') !== false) {
            $message = 'SQLSTATE[23000]: Integrity constraint violation: ' . $message;
            $code = 23000;
        }

        return new PDOException($message, $code);
    }

    protected function isRetryEligibleQuery(string $query): bool
    {
        foreach (static::RETRYABLE_QUERY_PATTERNS as $pattern) {
            if (preg_match($pattern, $query) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeBinding(mixed $binding): mixed
    {
        return match (true) {
            $binding instanceof BackedEnum => $binding->value,
            $binding instanceof DateTimeInterface => $binding->format('Y-m-d H:i:s'),
            $binding instanceof Stringable => (string) $binding,
            is_object($binding) && method_exists($binding, '__toString') => (string) $binding,
            default => $binding,
        };
    }
}
