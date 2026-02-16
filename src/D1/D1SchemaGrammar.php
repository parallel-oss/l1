<?php

namespace Parallel\L1\D1;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Str;

class D1SchemaGrammar extends SQLiteGrammar
{
    /**
     * Replace sqlite_master with D1's schema table in a compiled SQL string.
     */
    private function forD1(string $sql): string
    {
        return Str::of($sql)
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    /** @inheritDoc */
    public function compileSqlCreateStatement($schema, $name, $type = 'table')
    {
        return $this->forD1(parent::compileSqlCreateStatement($schema, $name, $type));
    }

    /** @inheritDoc */
    public function compileTableExists($schema, $table)
    {
        return $this->forD1(parent::compileTableExists($schema, $table));
    }

    /** @inheritDoc */
    public function compileLegacyTables($schema, $withSize = false)
    {
        return $this->forD1(parent::compileLegacyTables($schema, $withSize));
    }

    /** @inheritDoc */
    public function compileViews($schema)
    {
        return $this->forD1(parent::compileViews($schema));
    }

    /** @inheritDoc */
    public function compileDropAllTables($schema = null)
    {
        return $this->forD1(parent::compileDropAllTables($schema));
    }

    /** @inheritDoc */
    public function compileDropAllViews($schema = null)
    {
        return $this->forD1(parent::compileDropAllViews($schema));
    }
}
