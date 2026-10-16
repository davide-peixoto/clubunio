<?php

namespace Tests\Unit;

use App\ClubUnio\Services\PlanningCalendarTimeline;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PlanningCalendarTimelineTest extends TestCase
{
    private function row(int $id, string $start, string $end, int $team = 1, string $status = 'AKTIV'): object
    {
        return (object) ['id' => $id, 'starts_at' => $start, 'ends_at' => $end, 'team_season_id' => $team, 'status' => $status];
    }

    private function legacyConflicts(Collection $events, Collection $candidates): array
    {
        $groups = $candidates->where('status', 'AKTIV')->groupBy('team_season_id');
        $result = [];
        foreach ($events->where('status', 'AKTIV') as $event) {
            foreach ($groups[$event->team_season_id] ?? [] as $other) {
                if ($event->id !== $other->id && Time::parse($event->starts_at)->lt(Time::parse($other->ends_at))
                    && Time::parse($other->starts_at)->lt(Time::parse($event->ends_at))) {
                    $result[$event->id][] = $other;
                }
            }
        }

        return $result;
    }

    private function legacyDays(Time $start, Time $end, Collection $events, Collection $buses): array
    {
        $result = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $result[] = ['date' => $day,
                'events' => $events->filter(fn ($row) => Time::parse($row->starts_at)->lt($day->addDay()) && Time::parse($row->ends_at)->gt($day)),
                'buses' => $buses->filter(fn ($row) => Time::parse($row->starts_at)->lt($day->addDay()) && Time::parse($row->ends_at)->gt($day))];
        }

        return $result;
    }

    public function test_conflicts_preserve_touching_boundaries_subseconds_status_and_team(): void
    {
        $rows = collect([
            $this->row(1, '2026-10-16T12:00:00.000001+02:00', '2026-10-16T13:00:00+02:00'),
            $this->row(2, '2026-10-16T11:00:00+02:00', '2026-10-16T12:00:00.000001+02:00'),
            $this->row(3, '2026-10-16T11:00:00+02:00', '2026-10-16T12:00:00.000002+02:00'),
            $this->row(4, '2026-10-16T12:00:00+02:00', '2026-10-16T14:00:00+02:00', 2),
            $this->row(5, '2026-10-16T12:00:00+02:00', '2026-10-16T14:00:00+02:00', 1, 'ABGESAGT'),
        ]);
        $service = new PlanningCalendarTimeline;
        $result = $service->conflicts($rows->take(1), $rows);
        $this->assertSame([3], array_column($result[1], 'id'));
        $this->assertSame($this->legacyConflicts($rows, $rows), $service->conflicts($rows, $rows));
    }

    public function test_days_preserve_swiss_dst_multiday_bus_and_midnight_edges(): void
    {
        foreach (['2026-03-23', '2026-10-19'] as $date) {
            $start = Time::parse($date, 'Europe/Zurich');
            $events = collect([
                $this->row(1, $start->addDays(5)->setTime(23, 0)->toIso8601String(), $start->addDays(6)->setTime(4, 0)->toIso8601String()),
                $this->row(2, $start->subDay()->toIso8601String(), $start->toIso8601String()),
                $this->row(3, $start->addDay()->toIso8601String(), $start->addDays(2)->toIso8601String()),
            ]);
            $buses = collect([$this->row(1, $start->setTime(12, 0)->toIso8601String(), $start->addDays(3)->toIso8601String())]);
            $service = new PlanningCalendarTimeline;
            $result = $service->days($start, $start->addWeek(), $events, $buses);
            $this->assertEquals($this->legacyDays($start, $start->addWeek(), $events, $buses), $result);
            $this->assertSame([1], $result[5]['events']->pluck('id')->all());
            $this->assertSame([1], $result[6]['events']->pluck('id')->all());
            $this->assertCount(0, $result[3]['buses']);
        }
    }

    public function test_dense_calendar_matches_reference_without_mutating_input(): void
    {
        $start = Time::parse('2026-10-01', 'Europe/Zurich');
        $rows = collect();
        for ($i = 0; $i < 120; $i++) {
            $from = $start->addHours(($i * 37) % 720)->addMicroseconds($i);
            $rows->push($this->row($i + 1, $from->format('Y-m-d\TH:i:s.uP'), $from->addHours(($i % 30) + 1)->format('Y-m-d\TH:i:s.uP'), ($i % 4) + 1, $i % 13 === 0 ? 'ABGESAGT' : 'AKTIV'));
        }
        $before = serialize($rows);
        $service = new PlanningCalendarTimeline;
        $this->assertSame($this->legacyConflicts($rows->take(90), $rows), $service->conflicts($rows->take(90), $rows));
        $this->assertEquals($this->legacyDays($start, $start->addDays(42), $rows, $rows->take(10)), $service->days($start, $start->addDays(42), $rows, $rows->take(10)));
        $this->assertSame($before, serialize($rows));
    }

    public function test_empty_calendar_keeps_all_days_and_no_conflicts(): void
    {
        $start = Time::parse('2026-10-01', 'Europe/Zurich');
        $service = new PlanningCalendarTimeline;
        $this->assertSame([], $service->conflicts(collect(), collect()));
        $days = $service->days($start, $start->addDays(42), collect(), collect());
        $this->assertCount(42, $days);
        $this->assertEquals($this->legacyDays($start, $start->addDays(42), collect(), collect()), $days);
    }
}
