<?php

namespace Parallel\L1\D1;

use Illuminate\Database\SQLiteConnection;
use Parallel\L1\CloudflareD1Connector;
use Parallel\L1\D1\Pdo\D1Pdo;

class D1Connection extends SQLiteConnection
{
    /**
     * SQLite (and D1) limit on the number of bind parameters per statement.
     * Exceeding this causes "too many SQL variables" (SQLITE_ERROR).
     */
    public const SQLITE_MAX_BINDINGS = 999;

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
     * Run an insert statement against the database.
     * Chunks large multi-row inserts to stay under SQLite's bind parameter limit.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        $bindingCount = count($bindings);
        if ($bindingCount <= static::SQLITE_MAX_BINDINGS) {
            return parent::insert($query, $bindings);
        }

        $chunks = $this->chunkInsertQuery($query, $bindings);
        foreach ($chunks as [$chunkQuery, $chunkBindings]) {
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
        if (! preg_match('/\s+values\s*\(/i', $query)) {
            return [[$query, $bindings]];
        }

        $parts = preg_split('/\s+values\s*/i', $query, 2);
        $header = $parts[0];
        $valuesPart = $parts[1] ?? '';

        $placeholdersPerRow = $this->countPlaceholdersInFirstRow($valuesPart);
        if ($placeholdersPerRow <= 0) {
            return [[$query, $bindings]];
        }

        $maxBindingsPerChunk = static::SQLITE_MAX_BINDINGS;
        $rowsPerChunk = (int) floor($maxBindingsPerChunk / $placeholdersPerRow);
        if ($rowsPerChunk <= 0) {
            return [[$query, $bindings]];
        }

        $bindingsPerChunk = $rowsPerChunk * $placeholdersPerRow;
        $rowTemplate = $this->extractFirstValueRowTemplate($valuesPart);
        if ($rowTemplate === null) {
            return [[$query, $bindings]];
        }

        $chunks = [];
        $offset = 0;
        $totalBindings = count($bindings);
        while ($offset < $totalBindings) {
            $remaining = $totalBindings - $offset;
            $take = min($bindingsPerChunk, $remaining);
            $rowsInChunk = (int) floor($take / $placeholdersPerRow);
            if ($rowsInChunk <= 0) {
                break;
            }
            $take = $rowsInChunk * $placeholdersPerRow;
            $chunkBindings = array_slice($bindings, $offset, $take);
            $chunkValues = implode(', ', array_fill(0, $rowsInChunk, $rowTemplate));
            $chunkQuery = $header . ' values ' . $chunkValues;
            $chunks[] = [$chunkQuery, $chunkBindings];
            $offset += $take;
        }

        return $chunks;
    }

    /**
     * Count placeholders (?) in the first value row, e.g. "(?,?,?)" -> 3.
     */
    protected function countPlaceholdersInFirstRow(string $valuesPart): int
    {
        $start = strpos($valuesPart, '(');
        if ($start === false) {
            return 0;
        }
        $end = strpos($valuesPart, ')', $start);
        if ($end === false) {
            return 0;
        }
        $firstRow = substr($valuesPart, $start, $end - $start + 1);

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
        $end = strpos($valuesPart, ')', $start);
        if ($end === false) {
            return null;
        }

        return substr($valuesPart, $start, $end - $start + 1);
    }
}
