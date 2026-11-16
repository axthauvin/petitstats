<?php

declare(strict_types=1);

namespace Petitstats\Tests\Database;

use Petitstats\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function testARepeatedParameterGetsANameForEachUse(): void
    {
        [$sql, $placeholders] = DatabaseConnection::uniquePlaceholders(
            'SELECT id FROM t WHERE a >= :start AND b >= :start AND (c LIKE :q OR d LIKE :q OR e LIKE :q) AND f = :start_day',
        );

        $this->assertSame(
            'SELECT id FROM t WHERE a >= :start AND b >= :start__2 AND (c LIKE :q OR d LIKE :q__2 OR e LIKE :q__3) AND f = :start_day',
            $sql,
        );
        $this->assertSame([':start' => [':start', ':start__2'], ':q' => [':q', ':q__2', ':q__3'], ':start_day' => [':start_day']], $placeholders);
    }

    public function testTextThatLooksLikeAParameterIsLeftAlone(): void
    {
        $query = "SELECT DATE_FORMAT(timestamp, '%H:%i'), 'it''s :not_one', \"nor :this\" FROM t WHERE x = :x";

        [$sql, $placeholders] = DatabaseConnection::uniquePlaceholders($query);

        $this->assertSame($query, $sql);
        $this->assertSame([':x' => [':x']], $placeholders);
    }
}
