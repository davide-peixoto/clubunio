<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/** Real schema defaults/constraints; every write is session-local and rolled back. */
class PitchCompetitionTeamMappingPostgresTest extends PitchCompetitionTeamMappingTest
{
    protected function createDatabase(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt in to temporary PostgreSQL rollback tests.');
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->beginTransaction();
        $tables = ['tenants', 'accounts', 'tenant_accounts', 'competitions', 'sfv_schedule', 'teams', 'seasons', 'team_seasons',
            'object_changes', 'training_options', 'training_slots', 'members', 'member_statuses', 'team_staff', 'account_member_links'];
        foreach ($tables as $table) $this->db->statement("CREATE TEMP TABLE $table (LIKE clubora.$table INCLUDING ALL) ON COMMIT DROP");
        $queries = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_001500_pitch_planning.php'))->up());
        foreach ($queries as $query) {
            $sql = str_replace(['clubora.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']);
            $this->db->statement($sql);
        }
        $tables = array_merge($tables, ['pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks', 'pitch_competition_teams', 'pitch_competition_settings']);
        $this->db->setQueryGrammar(new class($this->db, $tables) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function __construct($connection, private array $tables) { parent::__construct($connection); }
            public function wrapTable($table, $prefix = null) {
                foreach ($this->tables as $name) {
                    if ($table === 'clubora.'.$name || str_starts_with($table, 'clubora.'.$name.' as ')) $table = str_replace('clubora.', 'pg_temp.', $table);
                }
                return parent::wrapTable($table, $prefix);
            }
            public function wrap($value) {
                if (is_string($value) && str_starts_with($value, 'clubora.')) $value = str_replace('clubora.', 'pg_temp.', $value);
                return parent::wrap($value);
            }
        });
        // Refuse any accidental write to the permanent schema, even if a new dependency is introduced.
        $this->db->beforeExecuting(function ($sql) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)/i', $sql) && str_contains($sql, 'clubora')) {
                throw new \LogicException('Permanent schema writes are forbidden in mapping tests');
            }
        });
    }
}
