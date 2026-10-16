<?php

// CLI-only synthetic CPU comparison. No database, accounts or external services.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';

use App\ClubUnio\Services\PlanningCalendarTimeline;
use Carbon\CarbonImmutable as Time;

$start = Time::parse('2026-10-01', 'Europe/Zurich');
$events = collect();
for ($i = 0; $i < 500; $i++) {
    $from = $start->addMinutes(($i * 23) % 44640);
    $events->push((object) ['id' => $i + 1, 'team_season_id' => ($i % 5) + 1, 'status' => 'AKTIV',
        'starts_at' => $from->toIso8601String(), 'ends_at' => $from->addHours(2)->toIso8601String()]);
}
$buses = $events->take(20);
$mode = $argv[1] ?? 'both';
if (! in_array($mode, ['legacy', 'optimized', 'both'], true)) {
    exit(1);
}
$results = [];
foreach ($mode === 'both' ? ['legacy', 'optimized'] : [$mode] as $algorithm) {
    $clock = hrtime(true);
    if ($algorithm === 'legacy') {
        $conflicts = [];
        $groups = $events->groupBy('team_season_id');
        foreach ($events as $event) {
            foreach ($groups[$event->team_season_id] as $other) {
                if ($event->id !== $other->id && Time::parse($event->starts_at)->lt(Time::parse($other->ends_at))
                    && Time::parse($other->starts_at)->lt(Time::parse($event->ends_at))) {
                    $conflicts[$event->id][] = $other;
                }
            }
        }
        $days = [];
        for ($day = $start; $day->lt($start->addDays(42)); $day = $day->addDay()) {
            $days[] = ['date' => $day,
                'events' => $events->filter(fn ($row) => Time::parse($row->starts_at)->lt($day->addDay()) && Time::parse($row->ends_at)->gt($day)),
                'buses' => $buses->filter(fn ($row) => Time::parse($row->starts_at)->lt($day->addDay()) && Time::parse($row->ends_at)->gt($day))];
        }
    } else {
        $service = new PlanningCalendarTimeline;
        $conflicts = $service->conflicts($events, $events);
        $days = $service->days($start, $start->addDays(42), $events, $buses);
    }
    $elapsed = round((hrtime(true) - $clock) / 1000000, 2);
    $digest = hash('sha256', serialize([
        array_map(fn ($rows) => array_column($rows, 'id'), $conflicts),
        array_map(fn ($day) => [$day['date']->toIso8601String(), $day['events']->pluck('id')->all(), $day['buses']->pluck('id')->all()], $days),
    ]));
    $results[] = ['algorithm' => $algorithm, 'elapsed_ms' => $elapsed, 'result_hash' => $digest];
}
if (count($results) === 2 && $results[0]['result_hash'] !== $results[1]['result_hash']) {
    throw new RuntimeException('Timeline result mismatch');
}
echo json_encode(['synthetic_events' => 500, 'days' => 42, 'buses' => 20,
    'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'results' => $results], JSON_THROW_ON_ERROR).PHP_EOL;
