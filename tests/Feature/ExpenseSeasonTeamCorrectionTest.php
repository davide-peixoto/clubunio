<?php

namespace Tests\Feature;

use App\ClubUnio\Services\ExpenseSeasonTeamCorrection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseSeasonTeamCorrectionTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.correction_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->db = DB::connection('correction_fixture');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $this->db->unprepared('CREATE TABLE clubora.expenses (id integer PRIMARY KEY, tenant_id integer, legacy_id integer, season_id integer, team_season_id integer, revision integer, active boolean, notes text, status_id integer, bexio_bill_id text, updated_at text);
CREATE TABLE clubora.team_seasons (id integer PRIMARY KEY, tenant_id integer, team_id integer, season_id integer, active boolean);
CREATE TABLE clubora.expense_lines (id integer PRIMARY KEY, tenant_id integer, expense_id integer, amount numeric, filename text);
CREATE TABLE clubora.object_changes (tenant_id integer, actor_id integer, object_type text, object_id integer, before_value text, after_value text);');
        $teams = [];
        foreach (ExpenseSeasonTeamCorrection::manifest() as [$id, $legacy, $season, $team, $old, $new]) {
            $teams[$new] = ['id' => $new, 'tenant_id' => 4, 'team_id' => $team, 'season_id' => $season, 'active' => true];
        }
        foreach (ExpenseSeasonTeamCorrection::manifest() as [$id, $legacy, $season, $team, $old, $new]) {
            $teams[$old] ??= ['id' => $old, 'tenant_id' => 4, 'team_id' => $team, 'season_id' => 1, 'active' => true];
            $this->db->table('clubora.expenses')->insert(['id' => $id, 'tenant_id' => 4, 'legacy_id' => $legacy,
                'season_id' => $season, 'team_season_id' => $old, 'revision' => 1, 'active' => true,
                'notes' => 'Preserve original', 'status_id' => 9, 'bexio_bill_id' => 'paid-fixture', 'updated_at' => '2020-01-01 00:00:00']);
            $this->db->table('clubora.expense_lines')->insert(['id' => $id, 'tenant_id' => 4, 'expense_id' => $id, 'amount' => 25.5, 'filename' => 'keep.pdf']);
        }
        $this->db->table('clubora.team_seasons')->insert(array_values($teams));
        $this->db->table('clubora.expenses')->insert(['id' => 9999, 'tenant_id' => 4, 'legacy_id' => 9999, 'notes' => 'Unrelated']);
    }

    private function runCorrection(callable $backup): array
    {
        return $this->db->transaction(fn () => (new ExpenseSeasonTeamCorrection)->apply($this->db, 4, 19, $backup));
    }

    public function test_exact_fourteen_same_team_links_change_with_audit_and_idempotent_retry(): void
    {
        $backup = null;
        $result = $this->runCorrection(function ($data) use (&$backup) { $backup = $data; });
        $this->assertSame(14, $result['changed']);
        $this->assertCount(14, $backup['expenses']);
        $this->assertSame(14, $result['line_rows_unchanged']);
        foreach (ExpenseSeasonTeamCorrection::manifest() as [$id, $legacy, $season, $team, $old, $new]) {
            $row = $this->db->table('clubora.expenses')->where('id', $id)->first();
            $this->assertSame($new, $row->team_season_id);
            $this->assertSame($season, $row->season_id);
            $this->assertSame(2, $row->revision);
            $this->assertSame('Preserve original', $row->notes);
            $this->assertSame('paid-fixture', $row->bexio_bill_id);
            $this->assertSame(9, $row->status_id);
        }
        $this->assertSame('Unrelated', $this->db->table('clubora.expenses')->where('id', 9999)->value('notes'));
        $this->assertSame(14, $this->db->table('clubora.object_changes')->count());
        $repeat = $this->runCorrection(fn () => throw new \LogicException('No second backup expected'));
        $this->assertTrue($repeat['already_applied']);
        $this->assertSame(0, $repeat['changed']);
        $this->assertSame(14, $this->db->table('clubora.object_changes')->count());
    }

    public function test_ambiguous_target_rolls_back_without_any_correction(): void
    {
        $this->db->table('clubora.team_seasons')->insert(['id' => 9999, 'tenant_id' => 4, 'team_id' => 3, 'season_id' => 31, 'active' => true]);
        $this->rejects('ambiguous');
    }

    public function test_missing_same_team_target_is_not_guessed(): void
    {
        $this->db->table('clubora.team_seasons')->where('id', 256)->delete();
        $this->rejects('missing');
    }

    public function test_stale_revision_is_not_overwritten(): void
    {
        $this->db->table('clubora.expenses')->where('id', 46)->update(['revision' => 3]);
        $this->rejects('concurrently');
    }

    public function test_backup_failure_prevents_mutation(): void
    {
        try { $this->runCorrection(fn () => throw new \RuntimeException('Backup failed')); $this->fail('Expected backup failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Backup failed', $e->getMessage()); }
        $this->assertSame(229, $this->db->table('clubora.expenses')->where('id', 46)->value('team_season_id'));
        $this->assertSame(0, $this->db->table('clubora.object_changes')->count());
    }

    public function test_transaction_rollback_restores_links_and_removes_new_audit_events(): void
    {
        $this->db->beginTransaction();
        (new ExpenseSeasonTeamCorrection)->apply($this->db, 4, 19, fn () => null);
        $this->db->rollBack();
        foreach (ExpenseSeasonTeamCorrection::manifest() as [$id, $legacy, $season, $team, $old, $new]) {
            $this->assertSame($old, $this->db->table('clubora.expenses')->where('id', $id)->value('team_season_id'));
        }
        $this->assertSame(0, $this->db->table('clubora.object_changes')->count());
    }

    private function rejects(string $message): void
    {
        try { $this->runCorrection(fn () => throw new \RuntimeException('Backup must not run')); $this->fail('Expected preflight failure'); }
        catch (\LogicException $e) { $this->assertStringContainsString($message, $e->getMessage()); }
        $this->assertSame(229, $this->db->table('clubora.expenses')->where('id', 46)->value('team_season_id'));
        $this->assertSame(0, $this->db->table('clubora.object_changes')->count());
    }
}
