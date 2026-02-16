<?php

namespace Parallel\L1\Test\LaravelDatabase;

use Illuminate\Tests\Database\DatabaseSQLiteBuilderTest as LaravelDatabaseSQLiteBuilderTest;
use Mockery as m;

/**
 * Runs Laravel's DatabaseSQLiteBuilderTest (D1 uses SQLiteBuilder for schema).
 */
class DatabaseSQLiteBuilderTest extends LaravelDatabaseSQLiteBuilderTest
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
