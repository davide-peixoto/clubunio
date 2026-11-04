<?php

namespace App\ClubUnio\Services;

/** Half-open time and spatial intervals: sharing an edge is not an overlap. */
final class PitchPlanningGeometry
{
    public static function overlaps(array $left, array $right, array $areas): bool
    {
        if ((int) $left['field_id'] !== (int) $right['field_id']) return false;
        if (strtotime($left['starts_at_iso']) >= strtotime($right['ends_at_iso']) || strtotime($right['starts_at_iso']) >= strtotime($left['ends_at_iso'])) return false;
        if (! $left['area_id'] || ! $right['area_id']) return true;
        $a = $areas[$left['area_id']] ?? null;
        $b = $areas[$right['area_id']] ?? null;
        // Missing/invalid historical geometry must not silently free capacity.
        if (! $a || ! $b) return true;
        return $a['x'] < round($b['x'] + $b['width'], 6) && $b['x'] < round($a['x'] + $a['width'], 6)
            && $a['y'] < round($b['y'] + $b['height'], 6) && $b['y'] < round($a['y'] + $a['height'], 6);
    }

    public static function conflicts(array $entry, array $entries, array $fields): array
    {
        $areas = [];
        $available = false;
        foreach ($fields as $field) {
            if ((int) $field['id'] === (int) $entry['field_id']) $available = $field['available'];
            foreach ($field['areas'] as $area) $areas[$area['id']] = $area;
        }
        $conflicts = $available ? [] : [['type' => 'field_unavailable', 'key' => null]];
        foreach ($entries as $other) {
            if ($entry['key'] === $other['key'] || $other['status'] !== 'approved') continue;
            if (self::overlaps($entry, $other, $areas)) $conflicts[] = [
                'type' => $other['source'] === 'block' ? 'block' : 'occupancy', 'key' => $other['key'],
            ];
        }
        return $conflicts;
    }
}
