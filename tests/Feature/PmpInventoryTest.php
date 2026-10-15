<?php

namespace Tests\Feature;

use App\ClubUnio\Services\PmpInventory;
use Tests\TestCase;

class PmpInventoryTest extends TestCase
{
    public function test_metadata_export_never_contains_secrets_sql_defaults_or_script_bodies(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pmp');
        try {
            file_put_contents($file, '<Project Version="26" SecPasswd="SECRET"><Database ConnectionString="PRIVATE"><Table TblName="Test" TblType="TABLE" TblGen="1"><Field FldName="MemberID" FldDefault="PRIVATE DEFAULT" FldTagLnkTbl="Mitglieder" FldTagLnkDisp2="Nachname" FldRequired="1" /></Table></Database><CustomScript ScriptName="Row_Updated" ScriptL1Key="Test"><![CDATA[SECRET BODY <!DOCTYPE html>]]></CustomScript></Project>');
            $result = (new PmpInventory)->read($file);
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SECRET', $json);
            $this->assertStringNotContainsString('PRIVATE', $json);
            $this->assertSame('Nachname', $result['tables']['Test']['fields'][0]['FldTagLnkDisp2']);
            $this->assertTrue($result['tables']['Test']['fields'][0]['has_default']);
            $this->assertSame(64, strlen($result['hooks'][0]['sha256']));
        } finally {
            unlink($file);
        }
    }

    public function test_real_dtd_is_rejected_without_resolving_entities(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pmp');
        try {
            file_put_contents($file, '<!DOCTYPE Project [<!ENTITY x SYSTEM "file:///private">]><Project>&x;</Project>');
            $this->expectException(\RuntimeException::class);
            (new PmpInventory)->read($file);
        } finally {
            unlink($file);
        }
    }

    public function test_every_inventory_object_has_exactly_one_reviewed_classification(): void
    {
        $inventory = json_decode(file_get_contents(base_path('deploy/pmp-coverage.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(104, $inventory['tables']);
        $this->assertCount(31, $inventory['relations']);
        $this->assertSame(array_keys($inventory['tables']), array_values(array_intersect(array_keys($inventory['tables']), array_keys($inventory['coverage']))));
        foreach ($inventory['coverage'] as $row) {
            foreach (array_filter(explode(', ', $row['evidence'])) as $file) {
                $this->assertFileExists(base_path('tests/Feature/'.$file));
            }
        }
        $this->assertSame('Ergaenzt59', $inventory['coverage']['MitgliederAenderungsantraege']['state']);
        $this->assertSame('Ergaenzt59', $inventory['coverage']['MitgliederAnhaenge']['state']);
    }
}
