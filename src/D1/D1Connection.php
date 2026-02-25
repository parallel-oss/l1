<?php

namespace Parallel\L1\D1;

use Illuminate\Database\SQLiteConnection;
use PDOException;
use Parallel\L1\CloudflareD1Connector;
use Parallel\L1\D1\Pdo\D1Pdo;

class D1Connection extends SQLiteConnection
{
    /**
     * Cloudflare D1 maximum number of bound parameters per query.
     * See: https://developers.cloudflare.com/d1/platform/limits/
     */
    public const SQLITE_MAX_BINDINGS = 100;

    public function __construct(
        protected CloudflareD1Connector $connector,
        protected $config = [],
    ) {
        parent::__construct(
            new D1Pdo('sqlite::memory:', $this->connector),
            $config['database'] ?? '',
            $config['prefix'] ?? '',
            $config,
        );
    }

    protected function getDefaultSchemaGrammar()
    {
        return new D1SchemaGrammar($this);
    }

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }

    /**
     * Run an SQL statement. Large multi-row INSERTs are chunked to stay under SQLite's bind limit.
     * (Laravel/Telescope etc. may call statement() directly, bypassing insert().)
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        $query = (string) $query;
        $bindings = (array) $bindings;

        if ($this->isLargeMultiRowInsert($query, $bindings)) {
            return $this->insert($query, $bindings);
        }

        if ($this->bindingCountExceedsLimit($bindings)) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'This statement cannot be safely chunked automatically. Split it into smaller batches.'
            );
        }

        return parent::statement($query, $bindings);
    }

    /**
     * Whether the query is a multi-row INSERT with too many bindings for a single statement.
     */
    protected function isLargeMultiRowInsert(string $query, array $bindings): bool
    {
        return $this->bindingCountExceedsLimit($bindings)
            && $this->isChunkableMultiRowInsert($query);
    }

    /**
     * Run an insert statement against the database.
     * Chunks large multi-row inserts to stay under SQLite's bind parameter limit.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        $query = (string) $query;
        $bindings = (array) $bindings;

        if (! $this->bindingCountExceedsLimit($bindings)) {
            return parent::insert($query, $bindings);
        }

        if (! $this->isChunkableMultiRowInsert($query)) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Only multi-row INSERT ... VALUES (...) statements can be chunked automatically.'
            );
        }

        $chunks = $this->chunkInsertQuery($query, $bindings);
        foreach ($chunks as [$chunkQuery, $chunkBindings]) {
            if ($this->bindingCountExceedsLimit($chunkBindings)) {
                $this->throwBindingLimitExceeded(
                    $chunkQuery,
                    $chunkBindings,
                    'A generated chunk still exceeds the D1 parameter limit.'
                );
            }
            parent::insert($chunkQuery, $chunkBindings);
        }

        return true;
    }

    /**
     * Split a multi-row INSERT query and bindings into chunks under the bind limit.
     *
     * @param  string  $query  Raw INSERT with multiple VALUES (?,?,...), (?,?,...)
     * @param  array  $bindings  Flat list of bind values
     * @return array<int, array{0: string, 1: array}>
     */
    protected function chunkInsertQuery(string $query, array $bindings): array
    {
        if (! $this->isChunkableMultiRowInsert($query)) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Only multi-row INSERT ... VALUES (...) statements can be chunked automatically.'
            );
        }

        $parts = preg_split('/\s+values\s*/i', $query, 2);
        if (! is_array($parts) || count($parts) < 2) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Unable to parse INSERT statement for chunking.'
            );
        }

        $header = rtrim($parts[0]);
        $valuesPart = ltrim($parts[1]);

        $placeholdersPerRow = $this->countPlaceholdersInFirstRow($valuesPart);
        if ($placeholdersPerRow <= 0) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Could not detect row placeholders for chunking.'
            );
        }

        if ($placeholdersPerRow > static::SQLITE_MAX_BINDINGS) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'A single INSERT row exceeds the D1 parameter limit.'
            );
        }

        if (count($bindings) % $placeholdersPerRow !== 0) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Placeholder count does not match provided bindings for chunking.'
            );
        }

        $maxBindingsPerChunk = static::SQLITE_MAX_BINDINGS;
        $rowsPerChunk = (int) floor($maxBindingsPerChunk / $placeholdersPerRow);
        if ($rowsPerChunk <= 0) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Cannot compute a valid chunk size for this INSERT.'
            );
        }

        $rowTemplate = $this->extractFirstValueRowTemplate($valuesPart);
        if ($rowTemplate === null) {
            $this->throwBindingLimitExceeded(
                $query,
                $bindings,
                'Unable to extract INSERT row template for chunking.'
            );
        }

        $chunks = [];
        $totalRows = (int) (count($bindings) / $placeholdersPerRow);

        for ($rowOffset = 0; $rowOffset < $totalRows; $rowOffset += $rowsPerChunk) {
            $rowsInChunk = min($rowsPerChunk, $totalRows - $rowOffset);
            $take = $rowsInChunk * $placeholdersPerRow;
            $bindingOffset = $rowOffset * $placeholdersPerRow;
            $chunkBindings = array_slice($bindings, $bindingOffset, $take);
            $chunkValues = implode(', ', array_fill(0, $rowsInChunk, $rowTemplate));
            $chunkQuery = $header . ' values ' . $chunkValues;
            $chunks[] = [$chunkQuery, $chunkBindings];
        }

        return $chunks;
    }

    /**
     * Count placeholders (?) in the first value row, e.g. "(?,?,?)" -> 3.
     */
    protected function countPlaceholdersInFirstRow(string $valuesPart): int
    {
        $firstRow = $this->extractFirstValueRowTemplate($valuesPart);
        if ($firstRow === null) {
            return 0;
        }

        return substr_count($firstRow, '?');
    }

    /**
     * Extract the first value row as template, e.g. "(?,?,?)".
     */
    protected function extractFirstValueRowTemplate(string $valuesPart): ?string
    {
        $start = strpos($valuesPart, '(');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($valuesPart);
        for ($index = $start; $index < $length; $index++) {
            $char = $valuesPart[$index];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($valuesPart, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    protected function isChunkableMultiRowInsert(string $query): bool
    {
        return (bool) preg_match('/^\s*insert\b.*\bvalues\s*\(/is', $query);
    }

    protected function bindingCountExceedsLimit(array $bindings): bool
    {
        return count($bindings) > static::SQLITE_MAX_BINDINGS;
    }

    /**
     * Throw an explicit error before reaching D1 when the query exceeds known limits.
     */
    protected function throwBindingLimitExceeded(string $query, array $bindings, string $reason): never
    {
        $operation = strtoupper((string) strtok(ltrim($query), " \t\n\r\0\x0B")) ?: 'QUERY';
        $message = sprintf(
            'D1 supports at most %d bound parameters per query. %s uses %d bindings. %s',
            static::SQLITE_MAX_BINDINGS,
            $operation,
            count($bindings),
            $reason,
        );

        throw new PDOException($message, static::SQLITE_MAX_BINDINGS);
    }
}
