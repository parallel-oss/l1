<?php

namespace Parallel\L1\Test\LaravelDatabase;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Facades\Facade;
use Illuminate\Tests\Database\DatabaseEloquentIntegrationTest as LaravelEloquentIntegrationTest;
use Parallel\L1\Test\D1DatabaseTestCase;
use ReflectionProperty;

/**
 * Runs Laravel's DatabaseEloquentIntegrationTest against the D1 driver
 * by bootstrapping a D1-backed app and reusing the framework's test methods.
 *
 * Requires a running D1 API (e.g. tests/worker). Default and second_connection
 * use DB1 and DB2 so both connections have separate databases.
 */
class D1EloquentIntegrationTest extends LaravelEloquentIntegrationTest
{
    private ?object $d1Bootstrap = null;

    /**
     * Bootstrap D1 app and create schema; do not call parent (SQLite Capsule).
     * Set Capsule as global using our app's DatabaseManager so DB:: and $this->connection() both work.
     */
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

        $capsule = new Capsule($app);
        $managerProp = new ReflectionProperty(Capsule::class, 'manager');
        $managerProp->setAccessible(true);
        $managerProp->setValue($capsule, $app['db']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->createSchema();
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

    /**
     * Drop all tables before creating so D1 (persistent) matches fresh :memory: behavior per test.
     */
    protected function createSchema(): void
    {
        $tablesDefault = [
            'test_orders', 'with_json', 'users_with_space_in_column_name', 'users_having_uuids',
            'users', 'unique_users', 'friends', 'posts', 'comments', 'friend_levels', 'photos',
            'soft_deleted_users', 'tags', 'taggables', 'categories', 'achievements',
            'eloquent_test_achievement_eloquent_test_user',
        ];
        $tablesSecond = [
            'test_items', 'users', 'unique_users', 'friends', 'posts', 'comments', 'friend_levels',
            'photos', 'soft_deleted_users', 'tags', 'taggables', 'categories', 'achievements',
            'eloquent_test_achievement_eloquent_test_user', 'non_incrementing_users',
        ];
        foreach ($tablesDefault as $table) {
            $this->schema('default')->dropIfExists($table);
        }
        foreach ($tablesSecond as $table) {
            $this->schema('second_connection')->dropIfExists($table);
        }
        parent::createSchema();
    }

    /**
     * D1 does not support transaction rollback across HTTP requests; each request is auto-committed.
     */
    public function testNestedTransactions(): void
    {
        $this->markTestSkipped('D1 does not support transaction rollback across requests.');
    }

    /**
     * D1 returns null date columns in a way that triggers Laravel's date cast to parse as current time.
     */
    public function testCanFillAndInsert(): void
    {
        $this->markTestSkipped('D1 null date handling in fillAndInsert differs from in-memory SQLite.');
    }
}
