<?php

namespace Parallel\L1\Test\LaravelDatabase;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Tests\Database\DatabaseIntegrationTest as LaravelDatabaseIntegrationTest;
use Parallel\L1\Test\D1DatabaseTestCase;

/**
 * Runs Laravel's DatabaseIntegrationTest against the D1 driver.
 * Overrides setUp (D1 app) and testQueryExecutedToRawSql (framework uses Capsule; we use DB facade).
 */
class DatabaseIntegrationTest extends LaravelDatabaseIntegrationTest
{
    private ?object $d1Bootstrap = null;

    protected function setUp(): void
    {
        $d1 = new class('d1_bootstrap') extends D1DatabaseTestCase {
            public function bootTestbench(): void
            {
                $this->setUp();
            }

            public function shutdownTestbench(): void
            {
                $this->tearDown();
            }
        };
        $d1->bootTestbench();
        $this->d1Bootstrap = $d1;

        $app = $d1->getApp();
        Facade::setFacadeApplication($app);
        Eloquent::setConnectionResolver($app['db']);
    }

    public function testQueryExecutedToRawSql(): void
    {
        $connection = DB::connection();

        $connection->listen(function (QueryExecuted $query) use (&$queryExecuted): void {
            $queryExecuted = $query;
        });

        $connection->select('select ?', [true]);

        $this->assertInstanceOf(QueryExecuted::class, $queryExecuted);
        $this->assertSame('select ?', $queryExecuted->sql);
        $this->assertSame([true], $queryExecuted->bindings);
        $this->assertSame('select 1', $queryExecuted->toRawSql());
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->d1Bootstrap !== null) {
                $this->d1Bootstrap->shutdownTestbench();
                $this->d1Bootstrap = null;
            }
        }
    }
}
