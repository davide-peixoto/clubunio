<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Competition, Tenant};
use Carbon\CarbonImmutable as Time;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SfvRescheduleImpact
{
    public function __construct(private PitchPlanningProjection $pitch, private TeamPlanningService $planning) {}

    public function preview(Account $actor, Tenant $tenant, array $preview): ?array
    {
        $before = $preview['before'] ?? null;
        $after = $preview['after'] ?? null;
        $placement = array_flip(['plays_on', 'starts_at', 'field_id']);
        if (! $before || ! $after || ($preview['kind'] ?? '') === 'withdrawal'
            || array_intersect_key($before, $placement) == array_intersect_key($after, $placement)) {
            return null;
        }

        $conflicts = [];
        if ($this->pitch->ready()) {
            $maySeeConflicts = app(ModulePermissionService::class)->allows($actor, $tenant, 'competitions', 'sync');
            $record = Competition::where('tenant_id', $tenant->id)->findOrFail($preview['target_id']);
            $record->forceFill($after);
            $settings = $this->pitch->table('pitch_competition_settings', $tenant)->where('competition_id', $record->id)->first();
            if ($settings) {
                $settings->withdrawn = false;
            }
            $entry = $this->pitch->competition($record, $settings, []);
            $entries = $this->pitch->entries($tenant, $entry['date'], Time::parse($entry['ends_at_iso'])->toDateString());
            $entriesByKey = array_column($entries, null, 'key');
            foreach (PitchPlanningGeometry::conflicts($entry, $entries, $this->pitch->fields($tenant)) as $conflict) {
                $other = $maySeeConflicts ? ($entriesByKey[$conflict['key']] ?? null) : null;
                $conflicts[] = ['key' => $maySeeConflicts ? $conflict['key'] : null,
                    'type' => $maySeeConflicts ? $conflict['type'] : 'restricted',
                    'source' => $maySeeConflicts ? ($other['source'] ?? null) : 'restricted', 'date' => $other['date'] ?? null,
                    'starts_at' => $other['starts_at'] ?? null, 'ends_at' => $other['ends_at'] ?? null,
                    'revision' => $other['revision'] ?? null];
            }
            usort($conflicts, fn ($a, $b) => [$a['key'], $a['type']] <=> [$b['key'], $b['type']]);
        }

        $starts = Time::parse($after['plays_on'].' '.$after['starts_at'], 'Europe/Zurich');
        $events = [];
        $restricted = 0;
        $linked = $this->planning->table($tenant, 'team_events')
            ->where('sfv_match_id', $after['association_match_id'])->where('status', 'AKTIV')->orderBy('id')->get();
        if ($linked->isNotEmpty()) {
            try {
                $this->planning->authorizeOperation($actor, $tenant, 'update');
                $managed = $this->planning->teams($actor, $tenant, true)->pluck('id')->flip();
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 403) {
                    throw $error;
                }
                $managed = collect();
            }
            foreach ($linked as $event) {
                if (! $managed->has($event->team_season_id) || $event->deadline_at === null) {
                    $restricted++;
                    continue;
                }
                $change = $this->eventChange($event, $starts, (string) $preview['field']);
                if ($change) {
                    $events[] = $change;
                }
            }
        }

        return ['conflicts' => $conflicts, 'events' => $events, 'restricted' => $restricted];
    }

    public function apply(Account $actor, Tenant $tenant, array $impact): void
    {
        foreach ($impact['events'] as $event) {
            $this->planning->saveEvent($actor, $tenant, $event['id'], $event['input']);
        }
    }

    private function eventChange(object $event, Time $starts, string $field): ?array
    {
        $oldStart = Time::parse($event->starts_at);
        $delta = $starts->timestamp - $oldStart->timestamp;
        if ($delta === 0 && $field === $event->location) {
            return null;
        }
        $input = array_intersect_key((array) $event, array_flip([
            'team_season_id', 'title', 'kind', 'mode', 'status', 'capacity', 'description', 'revision',
        ]));
        foreach (['starts_at', 'ends_at', 'deadline_at'] as $key) {
            $input[$key] = Time::parse($event->{$key})->addSeconds($delta)->tz('Europe/Zurich')->format('Y-m-d\TH:i');
        }
        $input['location'] = $field;

        return ['id' => (int) $event->id, 'input' => $input,
            'previous_start' => $oldStart->tz('Europe/Zurich')->format('Y-m-d H:i'), 'previous_location' => $event->location];
    }
}
