<?php

namespace Tests\Concerns;

use App\ClubUnio\Services\TeamPlanningService;

trait TemporaryPlanningRemovalSchema
{
    protected function temporaryPlanningRemovalSchema($db, int $tenant): TeamPlanningService
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1' || $db->getDriverName() !== 'pgsql'
            || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() < 1) {
            throw new \LogicException('Opt-in rolled-back PostgreSQL fixture required');
        }
        // Copy only the caller's newly-created synthetic tenant fixtures, never club data.
        $db->statement('CREATE TEMP TABLE team_events (LIKE clubunio.team_events INCLUDING ALL) ON COMMIT DROP');
        $db->statement('INSERT INTO pg_temp.team_events SELECT * FROM clubunio.team_events WHERE tenant_id = ?', [$tenant]);
        $db->statement('ALTER TABLE pg_temp.team_events DROP COLUMN IF EXISTS deleted_at');
        $ddl = $db->pretend(fn () => (require database_path('clubunio/2026_11_03_000087_planning_event_removal.php'))->up());
        $db->statement(str_replace('clubunio.team_events', 'pg_temp.team_events', $ddl[0]['query']));
        $db->setQueryGrammar(new class($db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                return parent::wrapTable($table === 'clubunio.team_events' ? 'pg_temp.team_events' : $table, $prefix);
            }
            public function wrap($value, $prefixAlias = false) {
                if (is_string($value)) { $value = str_replace('clubunio.team_events.', 'pg_temp.team_events.', $value); }
                return parent::wrap($value, $prefixAlias);
            }
        });
        $service = new class extends TeamPlanningService {
            protected function removalSchemaAvailable(): bool { return true; } // Real temporary DDL above, no policy override.
        };
        $this->app->instance(TeamPlanningService::class, $service);
        return $service;
    }
}
