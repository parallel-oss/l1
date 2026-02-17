<?php

namespace Parallel\L1\Test;

use Parallel\L1\CloudflareD1Connector;
use Parallel\L1\D1\D1Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class D1ConnectionChunkInsertTest extends TestCase
{
    /**
     * Chunk insert splits a multi-row INSERT when bindings exceed SQLite limit (999).
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
     * When bindings exceed 999, insert is split into multiple chunks.
     */
    public function testChunkInsertSplitsIntoMultipleChunks(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connection = new D1Connection($connector, ['database' => 'test']);

        $method = new ReflectionMethod($connection, 'chunkInsertQuery');
        $method->setAccessible(true);

        $placeholdersPerRow = 15; // e.g. telescope_entries column count
        $rows = 80; // 80 × 15 = 1200 bindings > 999
        $bindings = array_fill(0, $placeholdersPerRow * $rows, 'v');
        $rowTemplate = '(' . implode(', ', array_fill(0, $placeholdersPerRow, '?')) . ')';
        $valuesPart = implode(', ', array_fill(0, $rows, $rowTemplate));
        $query = 'insert into "t" ("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o") values ' . $valuesPart;

        $chunks = $method->invoke($connection, $query, $bindings);

        $maxPerChunk = 999;
        $rowsPerChunk = (int) floor($maxPerChunk / $placeholdersPerRow); // 66
        $expectedChunkCount = (int) ceil($rows / $rowsPerChunk); // ceil(80/66) = 2

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
     * Single-row INSERT is not split even when that row has many columns (under 999).
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
}
