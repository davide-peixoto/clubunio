<?php

namespace App\ClubUnio\Pages\Components;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Presentation only: all occurrences and permissions come from PitchPlanningService. */
final class PitchPlanningCalendar
{
    public const TIMEZONE = 'Europe/Zurich';

    public function range(string $date, string $mode): array
    {
        if (! in_array($mode, ['day', 'week', 'month'], true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new InvalidArgumentException('Invalid calendar range.');
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }
        $start = match ($mode) {
            'week' => $day->startOfWeek(),
            'month' => $day->startOfMonth()->startOfWeek(),
            default => $day,
        };
        $end = match ($mode) {
            'week' => $start->addWeek(),
            'month' => $day->endOfMonth()->startOfDay()->endOfWeek()->startOfDay()->addDay(),
            default => $start->addDay(),
        };

        return [$start, $end];
    }

    public function browse(string $date, string $mode, int $direction): string
    {
        $this->range($date, $mode);
        $day = CarbonImmutable::parse($date, self::TIMEZONE);
        $direction = $direction <=> 0;

        return match ($mode) {
            'month' => $day->addMonthsNoOverflow($direction)->toDateString(),
            'week' => $day->addWeeks($direction)->toDateString(),
            default => $day->addDays($direction)->toDateString(),
        };
    }

    public function days(string $date, string $mode, array $events): array
    {
        [$start, $end] = $this->range($date, $mode);
        $days = [];
        foreach ($events as &$event) {
            $event['_start'] = CarbonImmutable::parse($event['starts_at_iso'] ?? $event['starts_at'], self::TIMEZONE)->setTimezone(self::TIMEZONE);
            $event['_end'] = CarbonImmutable::parse($event['ends_at_iso'] ?? $event['ends_at'], self::TIMEZONE)->setTimezone(self::TIMEZONE);
        }
        unset($event);
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $rows = [];
            foreach ($events as $event) {
                if ($event['_start']->lt($day->addDay()) && $event['_end']->gt($day)) {
                    $event['time_label'] = $event['_start']->format('H:i').' - '.$event['_end']->format('H:i');
                    // Use wall-clock coordinates so the grid remains stable across DST transitions.
                    $event['minute'] = $event['_start']->lt($day) ? 0 : $event['_start']->hour * 60 + $event['_start']->minute;
                    $event['until'] = $event['_end']->gte($day->addDay()) ? 1440 : $event['_end']->hour * 60 + $event['_end']->minute;
                    unset($event['_start'], $event['_end']);
                    $rows[] = $event;
                }
            }
            usort($rows, fn ($a, $b) => [$a['minute'], $a['until'], $a['key']] <=> [$b['minute'], $b['until'], $b['key']]);
            $days[] = ['date' => $day, 'events' => $rows];
        }

        return $days;
    }

    public function columns(array $fields, array $events): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $rows = array_values(array_filter($events, fn ($row) => (string) $row['field_id'] === (string) $field['id']));
            $lanes = [];
            foreach ($rows as &$row) {
                $lane = 0;
                while (isset($lanes[$lane]) && $lanes[$lane] > $row['minute']) {
                    $lane++;
                }
                $lanes[$lane] = max($row['minute'] + 60, $row['until']);
                $row['lane'] = $lane;
            }
            unset($row);
            $columns[] = ['field' => $field, 'events' => $rows, 'lanes' => max(1, count($lanes))];
        }

        return $columns;
    }
}
