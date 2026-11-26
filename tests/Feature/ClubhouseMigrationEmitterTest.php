<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClubhouseMigrationEmitterTest extends TestCase
{
    public static function targets(): array
    {
        return [['clubunio_dev'], ['clubunio_stage'], ['clubunio_prod']];
    }

    #[DataProvider('targets')]
    public function test_owner_sql_is_bound_to_exact_target_without_runtime_ddl(string $database): void
    {
        $process = new Process([PHP_BINARY, base_path('deploy/emit-clubhouse-migration.php'), $database]);
        $process->mustRun();
        $sql = $process->getOutput();
        $this->assertStringContainsString("current_database()<>'{$database}'", $sql);
        $this->assertStringContainsString("current_user<>'postgres'", $sql);
        $this->assertStringContainsString('TO '.$database.'_app;', $sql);
        $this->assertStringContainsString('CREATE TABLE clubunio.clubhouse_settings', $sql);
        $this->assertStringContainsString('2026_11_24_030000_clubhouse', $sql);
        $this->assertStringNotContainsString('clubora.', $sql);
        $this->assertStringNotContainsString('GRANT ALL', $sql);
        $this->assertStringNotContainsString('GRANT CREATE', $sql);
        $this->assertStringNotContainsString('__ROLE__', $sql);
        $this->assertStringNotContainsString('__DATABASE__', $sql);
    }

    public function test_unknown_database_is_rejected_without_emitting_sql(): void
    {
        $process = new Process([PHP_BINARY, base_path('deploy/emit-clubhouse-migration.php'), 'clubunio_other']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
    }
}
