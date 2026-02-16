<?php

namespace Parallel\L1\Test\LaravelDatabase;

use Illuminate\Tests\Database\DatabaseSQLiteQueryGrammarTest as LaravelDatabaseSQLiteQueryGrammarTest;
use Mockery as m;

/**
 * Runs Laravel's DatabaseSQLiteQueryGrammarTest (D1 uses the same query grammar).
 */
class DatabaseSQLiteQueryGrammarTest extends LaravelDatabaseSQLiteQueryGrammarTest
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
