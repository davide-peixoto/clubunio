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
}
