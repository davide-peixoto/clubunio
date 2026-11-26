<?php

namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;
use LogicException;

final class StageMigrationTarget
{
    private static ?Connection $stage = null;

    public static function assertName(string $name): void
    {
        if (! preg_match('/\A[a-z0-9_]+\z/', $name) || ! preg_match('/(?:^|_)(?:stage|staging|stg)(?:_|$)/', $name)) {
            throw new LogicException('A separately named Stage database is required.');
        }
    }

    public static function authorize(Connection $connection, string $expected): void
    {
        self::assertName($expected);
        if (PHP_SAPI !== 'cli' || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== $expected
            || $connection->selectOne('SELECT current_database() AS name')->name !== $expected) {
            throw new LogicException('Unexpected Stage database identity.');
        }
        self::$stage = $connection;
    }

    public static function allows(Connection $connection): bool
    {
        return self::isStage($connection);
    }

    public static function isStage(Connection $connection): bool
    {
        return $connection === self::$stage;
    }
}
