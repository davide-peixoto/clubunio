<?php

namespace Tests\Unit;

use App\ClubUnio\Services\PitchPlanningGeometry;
use PHPUnit\Framework\TestCase;

class PitchFractionPresetsTest extends TestCase
{
    private function areas(): array
    {
        return array_combine(range(1, 7), require dirname(__DIR__, 2).'/deploy/pitch-fraction-presets.php') + [
            8 => ['x' => 0, 'y' => 0, 'width' => .5, 'height' => 1],
            9 => ['x' => .5, 'y' => 0, 'width' => .5, 'height' => 1],
        ];
    }

    private function entry(?int $area): array
    {
        return ['field_id' => 1, 'area_id' => $area, 'starts_at_iso' => '2026-11-05T18:00:00+01:00',
            'ends_at_iso' => '2026-11-05T19:00:00+01:00'];
    }

    public function test_fractions_tile_the_whole_pitch_without_gaps_or_overlaps(): void
    {
        $areas = $this->areas();
        foreach ([[1, 2, 3], [4, 5, 6, 7]] as $group) {
            $surface = 0;
            foreach ($group as $id) {
                $area = $areas[$id];
                $this->assertLessThanOrEqual(1, round($area['x'] + $area['width'], 6));
                $this->assertLessThanOrEqual(1, round($area['y'] + $area['height'], 6));
                $surface += $area['width'] * $area['height'];
                foreach ($group as $other) {
                    $this->assertSame($id === $other, PitchPlanningGeometry::overlaps($this->entry($id), $this->entry($other), $areas));
                }
                $this->assertNotEmpty($area['name_de']);
                $this->assertNotEmpty($area['name_en']);
                $this->assertNotSame($area['name_de'], $area['name_en']);
            }
            $this->assertSame(1.0, round($surface, 6));
        }
    }

    public function test_cross_partition_conflicts_use_geometry_not_different_area_ids(): void
    {
        $areas = $this->areas();
        // Third B crosses both halves and all four quarters; A/C only touch one half.
        $overlapping = [1 => [1, 4, 5, 8], 2 => [2, 4, 5, 6, 7, 8, 9], 3 => [3, 6, 7, 9]];
        foreach ($overlapping as $left => $matches) {
            foreach (array_keys($areas) as $right) {
                $expected = in_array($right, $matches, true);
                $this->assertSame($expected, PitchPlanningGeometry::overlaps($this->entry($left), $this->entry($right), $areas));
                $this->assertSame($expected, PitchPlanningGeometry::overlaps($this->entry($right), $this->entry($left), $areas));
            }
        }
        foreach ([4, 5, 6, 7] as $quarter) {
            $this->assertSame($quarter <= 5, PitchPlanningGeometry::overlaps($this->entry(8), $this->entry($quarter), $areas));
            $this->assertSame($quarter >= 6, PitchPlanningGeometry::overlaps($this->entry(9), $this->entry($quarter), $areas));
        }
    }

    public function test_whole_pitch_and_closures_still_block_every_fraction(): void
    {
        $areas = $this->areas();
        foreach (range(1, 7) as $id) {
            $entry = $this->entry($id) + ['key' => 'request:1'];
            $this->assertTrue(PitchPlanningGeometry::overlaps($entry, $this->entry(null), $areas));
            $field = ['id' => 1, 'available' => false, 'areas' => []];
            foreach ($areas as $key => $geometry) $field['areas'][] = ['id' => $key] + $geometry;
            $block = $this->entry(null) + ['key' => 'block:1', 'status' => 'approved', 'source' => 'block'];
            $this->assertSame(['field_unavailable', 'block'], array_column(PitchPlanningGeometry::conflicts($entry, [$block], [$field]), 'type'));
            $later = $this->entry($id);
            $later['starts_at_iso'] = $entry['ends_at_iso'];
            $later['ends_at_iso'] = '2026-11-05T20:00:00+01:00';
            $this->assertFalse(PitchPlanningGeometry::overlaps($entry, $later, $areas));
            $otherField = $this->entry($id);
            $otherField['field_id'] = 2;
            $this->assertFalse(PitchPlanningGeometry::overlaps($entry, $otherField, $areas));
        }
    }
}
