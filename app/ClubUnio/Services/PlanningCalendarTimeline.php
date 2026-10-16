<?php

namespace App\ClubUnio\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Collection;

/** Only receives already authorized rows; no queries or permission caching. */
class PlanningCalendarTimeline
{
    private function stamp(Time $date): int
    {
        return $date->getTimestamp() * 1000000 + (int) $date->format('u');
    }

    private function intervals(Collection $rows): array
    {
        return $rows->mapWithKeys(fn ($row) => [$row->id => [
            $this->stamp(Time::parse($row->starts_at)), $this->stamp(Time::parse($row->ends_at)),
        ]])->all();
    }

    public function conflicts(Collection $events, Collection $candidates): array
    {
        $times = $this->intervals($events);
        $candidateTimes = $this->intervals($candidates);
        $groups = $candidates->where('status', 'AKTIV')->groupBy('team_season_id');
        $conflicts = [];
        foreach ($events->where('status', 'AKTIV') as $event) {
            [$start, $end] = $times[$event->id];
            foreach ($groups[$event->team_season_id] ?? [] as $other) {
                [$otherStart, $otherEnd] = $candidateTimes[$other->id];
                if ($event->id !== $other->id && $start < $otherEnd && $otherStart < $end) {
                    $conflicts[$event->id][] = $other;
                }
            }
        }

        return $conflicts;
    }

    public function days(Time $start, Time $end, Collection $events, Collection $buses): array
    {
        $eventTimes = $this->intervals($events);
        $busTimes = $this->intervals($buses);
        $days = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $from = $this->stamp($day);
            $until = $this->stamp($day->addDay());
            $overlaps = fn ($times, $row) => $times[$row->id][0] < $until && $times[$row->id][1] > $from;
            $days[] = ['date' => $day, 'events' => $events->filter(fn ($row) => $overlaps($eventTimes, $row)),
                'buses' => $buses->filter(fn ($row) => $overlaps($busTimes, $row))];
        }

        return $days;
    }
}
