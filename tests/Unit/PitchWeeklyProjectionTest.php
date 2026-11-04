<?php

namespace Tests\Unit;

use App\ClubUnio\Api\Modules\ApiModuleDefinitions;
use App\ClubUnio\Models\{Team, TeamSeason, TrainingSlot};
use App\ClubUnio\Services\{PitchPlanningGeometry, PitchPlanningProjection, PitchWeeklyAreas};
use Tests\TestCase;

class PitchWeeklyProjectionTest extends TestCase
{
    private function slot(): TrainingSlot
    {
        $slot = new TrainingSlot;
        $slot->forceFill(['id' => 10, 'tenant_id' => 1, 'field_id' => 20, 'area_id' => 30, 'team_season_id' => 40,
            'starts_at' => '18:00', 'ends_at' => '19:30', 'revision' => 3]);
        $team = (new Team)->forceFill(['name' => 'Team']);
        $slot->setRelation('teamSeason', (new TeamSeason)->setRelation('team', $team));
        return $slot;
    }

    public function test_default_and_explicit_whole_field_exception_have_distinct_semantics(): void
    {
        $projection = new PitchPlanningProjection;
        $areas = collect([(object) ['id' => 30, 'tenant_id' => 1, 'field_id' => 20]])->keyBy('id');
        $slot = $this->slot();
        $base = $projection->training($slot, '2026-11-04', null, $areas);
        $this->assertSame(30, $base['area_id']);
        $this->assertSame('3:0', $base['revision']);
        $exception = (object) ['field_id' => 20, 'area_id' => null, 'revision' => 2];
        $whole = $projection->training($slot, '2026-11-04', $exception, $areas);
        $this->assertNull($whole['area_id']);
        $this->assertSame('3:2', $whole['revision']);
        $slot->revision = 4;
        $this->assertSame('4:2', $projection->training($slot, '2026-11-04', $exception, $areas)['revision']);
    }

    public function test_invalid_tenant_or_field_metadata_conservatively_occupies_whole_field(): void
    {
        foreach ([[2, 20], [1, 21]] as [$tenant, $field]) {
            $areas = collect([(object) ['id' => 30, 'tenant_id' => $tenant, 'field_id' => $field]])->keyBy('id');
            $entry = (new PitchPlanningProjection)->training($this->slot(), '2026-11-04', null, $areas);
            $this->assertNull($entry['area_id']);
        }
        $this->assertNull((new PitchPlanningProjection)->training($this->slot(), '2026-11-04', null, collect())['area_id']);
    }

    public function test_area_names_are_localized_with_documented_fallback_without_replacing_free_text(): void
    {
        $areas = new PitchWeeklyAreas;
        $area = (object) ['name_de' => 'West', 'name_en' => 'West end'];
        app()->setLocale('de');
        $this->assertSame('West', $areas->label($area));
        app()->setLocale('en');
        $this->assertSame('West end', $areas->label($area));
        $area->name_en = '';
        $this->assertSame('West', $areas->label($area));
        $this->assertSame('', $area->name_en);
    }

    public function test_api_exposes_only_the_area_identifier_as_optional_training_metadata(): void
    {
        $definition = ApiModuleDefinitions::all()['training_slots'];
        foreach (['read_fields', 'create_fields', 'update_fields'] as $kind) $this->assertContains('area_id', $definition[$kind]);
        $this->assertSame('revision', $definition['version']);
        $this->assertNotContains('tenant_id', $definition['create_fields']);
        $this->assertNotContains('pitch_area_name', $definition['create_fields']);
    }

    public function test_weekly_partial_allocations_use_existing_overlap_geometry(): void
    {
        $slot = $this->slot();
        $area = (object) ['id' => 30, 'tenant_id' => 1, 'field_id' => 20, 'x' => 0, 'y' => 0, 'width' => .5, 'height' => 1];
        $entry = (new PitchPlanningProjection)->training($slot, '2026-11-04', null, collect([$area])->keyBy('id'));
        $fields = [['id' => 20, 'available' => true, 'areas' => [(array) $area, ['id' => 31, 'x' => .5, 'y' => 0, 'width' => .5, 'height' => 1]]]];
        $other = array_replace($entry, ['key' => 'training:11:2026-11-04', 'area_id' => 31]);
        $this->assertSame([], PitchPlanningGeometry::conflicts($entry, [$entry, $other], $fields));
        $other['area_id'] = null;
        $this->assertCount(1, PitchPlanningGeometry::conflicts($entry, [$entry, $other], $fields));
    }
}
