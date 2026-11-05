<?php

namespace Tests\Unit;

use App\ClubUnio\Pages\Components\PitchPlanningCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PitchPlanningCalendarTest extends TestCase
{
    public function test_calendar_ranges_and_navigation_do_not_overflow_months(): void
    {
        $calendar = new PitchPlanningCalendar;
        [$start, $end] = $calendar->range('2026-11-04', 'week');
        $this->assertSame('2026-11-02', $start->toDateString());
        $this->assertSame('2026-11-09', $end->toDateString());
        $this->assertCount(42, $calendar->days('2026-11-04', 'month', []));
        $this->assertSame('2026-02-28', $calendar->browse('2026-01-31', 'month', 1));
        $this->assertSame('2026-10-28', $calendar->browse('2026-11-04', 'week', -1));
    }

    public function test_impossible_dates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PitchPlanningCalendar)->range('2026-02-30', 'day');
    }

    public function test_midnight_boundaries_and_dst_use_swiss_wall_clock(): void
    {
        $calendar = new PitchPlanningCalendar;
        $events = [
            ['key' => 'a', 'field_id' => 1, 'starts_at' => '2026-10-24T23:00:00+02:00', 'ends_at' => '2026-10-25T04:00:00+01:00'],
            ['key' => 'b', 'field_id' => 1, 'starts_at' => '2026-10-24T20:00:00+02:00', 'ends_at' => '2026-10-25T00:00:00+02:00'],
        ];
        $days = $calendar->days('2026-10-25', 'day', $events);
        $this->assertCount(1, $days[0]['events']);
        $this->assertSame(0, $days[0]['events'][0]['minute']);
        $this->assertSame(240, $days[0]['events'][0]['until']);
        $this->assertArrayNotHasKey('_start', $events[0]);
    }

    public function test_simultaneous_areas_get_separate_visual_lanes_and_empty_fields_remain(): void
    {
        $columns = (new PitchPlanningCalendar)->columns([['id' => 1], ['id' => 2]], [
            ['key' => 'a', 'field_id' => 1, 'minute' => 600, 'until' => 720],
            ['key' => 'b', 'field_id' => 1, 'minute' => 630, 'until' => 660],
            ['key' => 'c', 'field_id' => 1, 'minute' => 720, 'until' => 780],
        ]);
        $this->assertSame([0, 1, 0], array_column($columns[0]['events'], 'lane'));
        $this->assertSame([2, 2, 1], array_column($columns[0]['events'], 'lane_count'));
        $this->assertSame(2, $columns[0]['lanes']);
        $this->assertSame([], $columns[1]['events']);
        $this->assertSame(1, $columns[1]['lanes']);
    }

    public function test_width_groups_preserve_minimum_card_height_and_reset_after_dense_times(): void
    {
        $events = [
            ['key' => 'later', 'field_id' => 1, 'minute' => 720, 'until' => 800],
            ['key' => 'short', 'field_id' => 1, 'minute' => 600, 'until' => 615],
            ['key' => 'overlap', 'field_id' => 1, 'minute' => 620, 'until' => 640],
            ['key' => 'third', 'field_id' => 1, 'minute' => 630, 'until' => 645],
        ];
        $columns = (new PitchPlanningCalendar)->columns([['id' => 1]], $events);
        $this->assertSame(['short', 'overlap', 'third', 'later'], array_column($columns[0]['events'], 'key'));
        $this->assertSame([0, 1, 2, 0], array_column($columns[0]['events'], 'lane'));
        $this->assertSame([3, 3, 3, 1], array_column($columns[0]['events'], 'lane_count'));
        $this->assertSame(3, $columns[0]['lanes']);
        $this->assertArrayNotHasKey('lane', $events[0]);
    }
}
