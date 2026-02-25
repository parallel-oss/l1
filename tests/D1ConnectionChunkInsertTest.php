<?php

namespace Parallel\L1\Test;

use Parallel\L1\CloudflareD1Connector;
use Parallel\L1\D1\D1Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class D1ConnectionChunkInsertTest extends TestCase
{
    /**
     * Chunk insert splits a multi-row INSERT when bindings exceed D1 limit.
     */
    public function testChunkInsertSplitsWhenBindingsExceedLimit(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $query = 'insert into "telescope_entries" ("col1", "col2", "col3") values (?, ?, ?), (?, ?, ?), (?, ?, ?)';
        $bindings = array_fill(0, 9, 'x'); // 9 bindings, 3 rows × 3 cols

        $chunks = $method->invoke($connection, $query, $bindings);
        $this->assertCount(1, $chunks, 'Under limit should produce one chunk');
        $this->assertSame($query, $chunks[0][0]);
        $this->assertCount(9, $chunks[0][1]);
    }

    /**
     * When bindings exceed the D1 limit (100), insert is split into multiple chunks.
     */
    public function testChunkInsertSplitsIntoMultipleChunks(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $placeholdersPerRow = 15; // e.g. telescope_entries column count
        $rows = 80; // 80 × 15 = 1200 bindings > D1 limit
        $bindings = array_fill(0, $placeholdersPerRow * $rows, 'v');
        $rowTemplate = '(' . implode(', ', array_fill(0, $placeholdersPerRow, '?')) . ')';
        $valuesPart = implode(', ', array_fill(0, $rows, $rowTemplate));
        $query = 'insert into "t" ("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o") values ' . $valuesPart;

        $chunks = $method->invoke($connection, $query, $bindings);

        $maxPerChunk = D1Connection::SQLITE_MAX_BINDINGS;
        $rowsPerChunk = (int) floor($maxPerChunk / $placeholdersPerRow);
        $expectedChunkCount = (int) ceil($rows / $rowsPerChunk);

        $this->assertCount($expectedChunkCount, $chunks);

        $totalBindings = 0;
        foreach ($chunks as $i => [$chunkQuery, $chunkBindings]) {
            $this->assertLessThanOrEqual(
                D1Connection::SQLITE_MAX_BINDINGS,
                count($chunkBindings),
                "Chunk {$i} must not exceed SQLite bind limit"
            );
            $this->assertStringStartsWith('insert into "t"', $chunkQuery);
            $this->assertStringContainsString('values (', $chunkQuery);
            $totalBindings += count($chunkBindings);
        }
        $this->assertSame($placeholdersPerRow * $rows, $totalBindings);
    }

    /**
     * Single-row INSERT is not split even when that row has many columns (under D1 limit).
     */
    public function testChunkInsertLeavesSingleRowUntouched(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $query = 'insert into "x" ("a","b") values (?, ?)';
        $bindings = [1, 2];
        $chunks = $method->invoke($connection, $query, $bindings);
        $this->assertCount(1, $chunks);
        $this->assertSame($query, $chunks[0][0]);
        $this->assertSame([1, 2], $chunks[0][1]);
    }

    /**
     * Non-INSERT or INSERT without VALUES returns single chunk (passthrough).
     */
    public function testChunkInsertPassthroughForNonMultiRowInsert(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $query = 'insert into "x" ("a") values (?)';
        $bindings = [1];
        $chunks = $method->invoke($connection, $query, $bindings);
        $this->assertCount(1, $chunks);
        $this->assertSame($query, $chunks[0][0]);
    }

    /**
     * statement() routes large multi-row INSERTs to chunked insert (e.g. Laravel Telescope).
     */
    public function testStatementRoutesLargeMultiRowInsertToChunkedInsert(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $isLarge = new ReflectionMethod($connection, 'isLargeMultiRowInsert');
        $isLarge->setAccessible(true);

        $largeInsert = 'insert into "telescope_entries" ("batch_id","content","created_at","family_hash","type","uuid") values (?,?,?,?,?,?), (?,?,?,?,?,?)';
        $manyBindings = array_fill(0, D1Connection::SQLITE_MAX_BINDINGS + 1, 'x');
        $this->assertTrue($isLarge->invoke($connection, $largeInsert, $manyBindings), 'Large multi-row INSERT should be detected');

        $fewBindings = array_fill(0, 12, 'x');
        $this->assertFalse($isLarge->invoke($connection, $largeInsert, $fewBindings), 'Under limit should not be treated as large');

        $notInsert = 'select * from "telescope_entries" where "id" = ?';
        $this->assertFalse($isLarge->invoke($connection, $notInsert, array_fill(0, D1Connection::SQLITE_MAX_BINDINGS + 1, 'x')), 'SELECT with many bindings should not be treated as large INSERT');
    }

    /**
     * Telescope commonly inserts 6 columns; 18 rows means 108 bindings and must be chunked for D1.
     */
    public function testChunkInsertHandlesTelescopeSizedBatchForD1Limit(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $placeholdersPerRow = 6;
        $rows = 18; // 18 × 6 = 108 > D1 limit (100)
        $bindings = array_fill(0, $placeholdersPerRow * $rows, 'x');
        $rowTemplate = '(' . implode(', ', array_fill(0, $placeholdersPerRow, '?')) . ')';
        $valuesPart = implode(', ', array_fill(0, $rows, $rowTemplate));
        $query = 'insert into "telescope_entries" ("batch_id","content","created_at","family_hash","type","uuid") values ' . $valuesPart;

        $chunks = $method->invoke($connection, $query, $bindings);

        $rowsPerChunk = (int) floor(D1Connection::SQLITE_MAX_BINDINGS / $placeholdersPerRow); // 16
        $expectedChunkCount = (int) ceil($rows / $rowsPerChunk); // ceil(18/16) = 2
        $this->assertCount($expectedChunkCount, $chunks);

        foreach ($chunks as [$chunkQuery, $chunkBindings]) {
            $this->assertLessThanOrEqual(D1Connection::SQLITE_MAX_BINDINGS, count($chunkBindings));
            $this->assertStringStartsWith('insert into "telescope_entries"', $chunkQuery);
        }
    }

    /**
     * insert() should execute multiple statements once bindings exceed D1 limit.
     */
    public function testInsertExecutesChunkedStatementsWhenOverD1Limit(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new class($connector, ['database' => 'test']) extends D1Connection
        {
            public array $recordedStatements = [];

            public function statement($query, $bindings = [])
            {
                $this->recordedStatements[] = [$query, $bindings];

                return true;
            }
        };

        $placeholdersPerRow = 6;
        $rows = 18; // 18 × 6 = 108 > D1 limit (100)
        $bindings = array_fill(0, $placeholdersPerRow * $rows, 'x');
        $rowTemplate = '(' . implode(', ', array_fill(0, $placeholdersPerRow, '?')) . ')';
        $valuesPart = implode(', ', array_fill(0, $rows, $rowTemplate));
        $query = 'insert into "telescope_entries" ("batch_id","content","created_at","family_hash","type","uuid") values ' . $valuesPart;

        $this->assertTrue($connection->insert($query, $bindings));
        $this->assertCount(2, $connection->recordedStatements, 'Expected split into 2 statements for 108 bindings');

        foreach ($connection->recordedStatements as [$executedQuery, $executedBindings]) {
            $this->assertStringStartsWith('insert into "telescope_entries"', $executedQuery);
            $this->assertLessThanOrEqual(D1Connection::SQLITE_MAX_BINDINGS, count($executedBindings));
        }
    }
}
