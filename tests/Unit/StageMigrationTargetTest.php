<?php

namespace Tests\Unit;

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StageMigrationTargetTest extends TestCase
{
    #[DataProvider('forbiddenNames')]
    public function test_stage_runner_rejects_non_stage_names(string $name): void
    {
        $this->expectException(LogicException::class);
        StageMigrationTarget::assertName($name);
    }

    public static function forbiddenNames(): array
    {
        return [['db_acdf8d_dclubora'], ['db_acdf8d_mysvs'], ['postgres'], ['stage;dbname=prod'], ['']];
    }

    public function test_configured_name_is_not_enough_if_connected_identity_differs(): void
    {
        $connection = $this->connection('clubunio_stage', 'db_acdf8d_dclubora');
        $this->assertFalse(StageMigrationTarget::allows($connection));
        $this->expectException(LogicException::class);
        StageMigrationTarget::authorize($connection, 'clubunio_stage');
    }

    public function test_authorization_is_bound_to_the_verified_connection_instance(): void
    {
        $connection = $this->connection('clubunio_stage', 'clubunio_stage');
        StageMigrationTarget::authorize($connection, 'clubunio_stage');
        $this->assertTrue(StageMigrationTarget::allows($connection));
        $this->assertFalse(StageMigrationTarget::allows($this->connection('clubunio_stage', 'clubunio_stage')));
        $this->assertTrue(StageMigrationTarget::allows($this->connection('db_acdf8d_dclubora', 'db_acdf8d_dclubora')));
    }

    private function connection(string $configured, string $actual): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn($configured);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->method('selectOne')->with('SELECT current_database() AS name')->willReturn((object) ['name' => $actual]);

        return $connection;
    }
}
