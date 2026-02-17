<?php

namespace Parallel\L1\D1\Pdo;

use PDO;
use PDOStatement;
use Parallel\L1\CloudflareD1Connector;

class D1Pdo extends PDO
{
    protected array $lastInsertIds = [];

    protected bool $inTransaction = false;

    public function __construct(
        protected string $dsn,
        protected CloudflareD1Connector $connector,
    ) {
        parent::__construct('sqlite::memory:');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new D1PdoStatement(
            $this,
            $query,
            $options,
        );
    }

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }

    public function setLastInsertId($name = null, $value = null): void
    {
        if ($name === null) {
            $name = 'id';
        }

        $this->lastInsertIds[$name] = $value;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        if ($name === null) {
            $name = 'id';
        }

        $value = $this->lastInsertIds[$name] ?? null;
        if ($value === null || $value === false) {
            return false;
        }

        return (string) $value;
    }

    public function beginTransaction(): bool
    {
        return $this->inTransaction = true;
    }

    public function commit(): bool
    {
        return ! ($this->inTransaction = false);
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
