<?php

namespace Parallel\L1\D1\Pdo;

use Illuminate\Support\Arr;
use PDO;
use PDOException;
use PDOStatement;

class D1PdoStatement extends PDOStatement
{
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

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;

        return true;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
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

    public function execute($params = []): bool
    {
        $this->bindings = array_values($this->bindings ?: $params);

        $response = $this->pdo->d1()->databaseQuery(
            $this->query,
            $this->bindings,
        );

        if ($response->failed() || ! $response->json('success')) {
            $message = (string) $response->json('errors.0.message');
            $code = (int) $response->json('errors.0.code');
            if (stripos($message, 'UNIQUE constraint') !== false || stripos($message, 'SQLITE_CONSTRAINT') !== false) {
                $message = 'SQLSTATE[23000]: Integrity constraint violation: ' . $message;
                $code = 23000;
            }
            throw new PDOException($message, $code);
        }

        $this->responses = $response->json('result');
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

    public function fetch(int $mode = PDO::FETCH_DEFAULT, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0): mixed
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

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array
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
            ->map(fn ($response) => $response['results'])
            ->collapse()
            ->toArray();
    }
}
