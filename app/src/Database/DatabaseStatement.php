<?php

declare(strict_types=1);

namespace Petitstats\Database;

use PDO;
use PDOStatement;
use SQLite3Stmt;

/** Prepared statement wrapper exposing the SQLite3Stmt API over SQLite or PDO. */
final class DatabaseStatement
{
    public function __construct(private SQLite3Stmt|PDOStatement $statement, private bool $mysql) {}
    public function bindValue(string $name, mixed $value, int $type = SQLITE3_TEXT): bool
    {
        if (!$this->mysql) {
            return $this->statement->bindValue($name, $value, $type);
        }
        $pdoType = $type === SQLITE3_INTEGER ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        return $this->statement->bindValue($name, $value, $pdoType);
    }
    public function execute(): DatabaseResult|false
    {
        if ($this->statement instanceof SQLite3Stmt) {
            $result = $this->statement->execute();
            return $result === false ? false : new DatabaseResult($result);
        }
        return $this->statement->execute() ? new DatabaseResult($this->statement) : false;
    }
}
