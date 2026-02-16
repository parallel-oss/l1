<?php

namespace Parallel\L1\Test\LaravelDatabase;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Tests\Database\DatabaseSQLiteSchemaGrammarTest as LaravelDatabaseSQLiteSchemaGrammarTest;
use Mockery as m;
use Parallel\L1\D1\D1SchemaGrammar;
use Parallel\L1\Test\D1DatabaseTestCase;

/**
 * Runs Laravel's DatabaseSQLiteSchemaGrammarTest against D1SchemaGrammar.
 * Overrides getGrammar (D1), testDropColumn/testRenameIndex (real D1 connection), and tearDown (Mockery).
 */
class DatabaseSQLiteSchemaGrammarTest extends LaravelDatabaseSQLiteSchemaGrammarTest
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function getGrammar(?Connection $connection = null)
    {
        return new D1SchemaGrammar($connection ?? $this->getConnection());
    }

    public function testDropColumn(): void
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

        try {
            $app = $d1->getApp();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

            $schema = DB::connection()->getSchemaBuilder();
            $table = 'schema_grammar_drop_column';

            $schema->create($table, function (Blueprint $t) {
                $t->string('email');
                $t->string('name');
            });

            $this->assertTrue($schema->hasTable($table));
            $this->assertTrue($schema->hasColumn($table, 'name'));

            $schema->table($table, function (Blueprint $t) {
                $t->dropColumn('name');
            });

            $this->assertFalse($schema->hasColumn($table, 'name'));

            $schema->drop($table);
        } finally {
            $d1->shutdownTestbench();
        }
    }

    public function testRenameIndex(): void
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

        try {
            $app = $d1->getApp();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

            $schema = DB::connection()->getSchemaBuilder();
            $table = 'schema_grammar_rename_index';

            $schema->create($table, function (Blueprint $t) {
                $t->string('name');
                $t->string('email');
            });

            $schema->table($table, function (Blueprint $t) {
                $t->index(['name', 'email'], 'index1');
            });

            $indexes = $schema->getIndexListing($table);
            $this->assertContains('index1', $indexes);
            $this->assertNotContains('index2', $indexes);

            $schema->table($table, function (Blueprint $t) {
                $t->renameIndex('index1', 'index2');
            });

            $this->assertFalse($schema->hasIndex($table, 'index1'));
            $this->assertTrue(collect($schema->getIndexes($table))->contains(
                fn ($index) => $index['name'] === 'index2' && $index['columns'] === ['name', 'email']
            ));

            $schema->drop($table);
        } finally {
            $d1->shutdownTestbench();
        }
    }
}
