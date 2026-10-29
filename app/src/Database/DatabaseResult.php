<?php

declare(strict_types=1);

namespace Petitstats\Database;

use PDO;
use PDOStatement;
use SQLite3Result;

/**
 * Compatibility layer used by the analytics endpoints.  Keeping the small
 * SQLite-style API here makes the storage engine replaceable without leaking
 * PDO details into every dashboard endpoint.
 */
final class DatabaseResult
{
    public function __construct(private SQLite3Result|PDOStatement|false $result) {}
    public function fetchArray(int $mode = SQLITE3_ASSOC): array|false
    {
        if ($this->result instanceof SQLite3Result) {
            return $this->result->fetchArray($mode);
        }
        return $this->result instanceof PDOStatement ? $this->result->fetch($mode === SQLITE3_NUM ? PDO::FETCH_NUM : PDO::FETCH_ASSOC) : false;
    }
}
