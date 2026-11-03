<?php

namespace Tests\Feature;

use App\ClubUnio\Services\SeasonReferenceAudit;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeasonReferenceAuditTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.season_audit_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->db = DB::connection('season_audit_fixture');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $this->db->statement("ATTACH DATABASE ':memory:' AS legacy_prod");
        $this->db->unprepared('CREATE TABLE clubora.seasons (id integer, tenant_id integer, legacy_id integer);
CREATE TABLE clubora.invoices (id integer, tenant_id integer, legacy_id integer, season_id integer);
CREATE TABLE legacy_prod.Rechnung (RechnungsID integer, SaisonID integer);');
        $this->db->table('clubora.seasons')->insert([['id' => 37, 'tenant_id' => 4, 'legacy_id' => 38],
            ['id' => 38, 'tenant_id' => 4, 'legacy_id' => 39], ['id' => 99, 'tenant_id' => 9, 'legacy_id' => 90]]);
        $this->db->table('legacy_prod.Rechnung')->insert([['RechnungsID' => 100, 'SaisonID' => 38], ['RechnungsID' => 101, 'SaisonID' => null]]);
        $this->db->table('clubora.invoices')->insert([['id' => 1, 'tenant_id' => 4, 'legacy_id' => 100, 'season_id' => 37],
            ['id' => 2, 'tenant_id' => 4, 'legacy_id' => 101, 'season_id' => null]]);
    }

    private function audit(): array
    {
        $this->db->statement('PRAGMA query_only=ON');
        return (new SeasonReferenceAudit)->compareMapping($this->db, 4, ['invoices', 'season_id', 'Rechnung', 'RechnungsID', 'SaisonID', 'seasons']);
    }

    public function test_shifted_ids_and_nullable_references_match_without_writing(): void
    {
        $report = $this->audit();
        $this->assertSame(2, $report['compared_rows']);
        foreach (['different_reference', 'unresolved_source_reference', 'source_rows_without_target', 'imported_rows_without_snapshot'] as $key) {
            $this->assertSame(0, $report[$key]);
        }
    }

    public function test_accidentally_copied_legacy_id_is_detected_even_when_native_fk_exists(): void
    {
        $this->db->table('clubora.invoices')->where('id', 1)->update(['season_id' => 38]);
        $this->assertSame(1, $this->audit()['different_reference']);
    }

    public function test_foreign_tenant_reference_and_unresolved_source_are_detected(): void
    {
        $this->db->table('legacy_prod.Rechnung')->where('RechnungsID', 100)->update(['SaisonID' => 90]);
        $this->db->table('clubora.invoices')->where('id', 1)->update(['season_id' => 99]);
        $report = $this->audit();
        $this->assertSame(1, $report['different_reference']);
        $this->assertSame(1, $report['unresolved_source_reference']);
    }

    public function test_missing_and_extra_imported_records_are_reported_not_repaired(): void
    {
        $this->db->table('clubora.invoices')->where('id', 1)->delete();
        $this->db->table('clubora.invoices')->insert(['id' => 3, 'tenant_id' => 4, 'legacy_id' => 999, 'season_id' => 37]);
        $report = $this->audit();
        $this->assertSame(1, $report['source_rows_without_target']);
        $this->assertSame(1, $report['imported_rows_without_snapshot']);
        $this->assertSame(2, $this->db->table('clubora.invoices')->count());
    }

    public function test_authorized_team_correction_requires_matching_before_and_after_evidence(): void
    {
        $this->db->unprepared('CREATE TABLE clubora.team_seasons (id integer, tenant_id integer, legacy_id integer);
CREATE TABLE clubora.expenses (id integer, tenant_id integer, legacy_id integer, team_season_id integer);
CREATE TABLE legacy_prod.Spesenbelege (SpesenID integer, TeamSaisonID integer);
CREATE TABLE clubora.object_changes (tenant_id integer, object_type text, object_id integer, before_value text, after_value text);');
        $this->db->table('clubora.team_seasons')->insert(['id' => 229, 'tenant_id' => 4, 'legacy_id' => 1000]);
        $this->db->table('clubora.expenses')->insert(['id' => 46, 'tenant_id' => 4, 'legacy_id' => 102, 'team_season_id' => 256]);
        $this->db->table('legacy_prod.Spesenbelege')->insert(['SpesenID' => 102, 'TeamSaisonID' => 1000]);
        $mapping = ['expenses', 'team_season_id', 'Spesenbelege', 'SpesenID', 'TeamSaisonID', 'team_seasons'];
        $audit = new SeasonReferenceAudit;
        $this->assertSame(1, $audit->compareMapping($this->db, 4, $mapping)['unexplained_reference_differences']);
        $this->db->table('clubora.object_changes')->insert(['tenant_id' => 4, 'object_type' => 'expenses', 'object_id' => 46,
            'before_value' => json_encode(['team_season_id' => 229]), 'after_value' => json_encode(['team_season_id' => 256, 'correction_key' => 'expense_team_season_fix_20261103'])]);
        $report = $audit->compareMapping($this->db, 4, $mapping);
        $this->assertSame(1, $report['different_reference']);
        $this->assertSame(1, $report['recognized_correction_differences']);
        $this->assertSame(0, $report['unexplained_reference_differences']);
        $this->db->table('clubora.object_changes')->update(['before_value' => json_encode(['team_season_id' => 999])]);
        $this->assertSame(1, $audit->compareMapping($this->db, 4, $mapping)['unexplained_reference_differences']);
    }
}
