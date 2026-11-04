<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

/** FacilityService adapter only; planner and SFV saves own their separate events. */
class PitchCompetitionEdits
{
    public function __construct(private PitchPlanningProjection $projection) {}

    /** Called after validation, under FacilityService's tenant and source row locks. */
    public function beforeSave(Account $actor, Tenant $tenant, Competition $record, array $data): ?array
    {
        $this->assertTransaction($tenant, $record);
        $candidate = (clone $record)->forceFill($data);
        if ($record->exists && $this->placement($record) === $this->placement($candidate)) {
            return null;
        }
        // No planner tables or events are required by the legacy facility editor.
        if (! $this->projection->ready()) {
            return null;
        }
        $this->authorize($actor, $tenant, $record, $candidate);
        $before = $record->exists ? $this->snapshot($tenant, $record) : null;
        if (! $candidate->exists) {
            $candidate->id = 0;
            $candidate->tenant_id = $tenant->id;
        }
        $after = $this->snapshot($tenant, $candidate);
        if ($this->occupies($after)) {
            // Even an SFV-linked source edited manually is not an authoritative import.
            $conflicts = PitchPlanningGeometry::conflicts($after,
                $this->projection->entries($tenant, $after['date'], Time::parse($after['ends_at_iso'])->toDateString()),
                $this->projection->fields($tenant));
            abort_if($conflicts !== [], 409, __('Das ausgewählte Spielfeld oder die Teilfläche ist nicht verfügbar.'));
        }

        return ['before' => $before];
    }

    /** Audit and inbox/outbox fanout are part of the source transaction, never delivery. */
    public function afterSave(Account $actor, Tenant $tenant, Competition $record, array $context): void
    {
        $this->assertTransaction($tenant, $record);
        $settings = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $record->id)->first();
        if ($settings?->area_id && ! $this->projection->table('pitch_areas', $tenant)
            ->where('id', $settings->area_id)->where('field_id', $record->field_id)->exists()) {
            $this->projection->table('pitch_competition_settings', $tenant)->where('id', $settings->id)
                ->update(['area_id' => null, 'revision' => $settings->revision + 1, 'updated_at' => now()]);
        }
        $before = $context['before'];
        $after = $this->snapshot($tenant, $record);
        $wasPlaced = $before !== null && $this->occupies($before);
        $isPlaced = $this->occupies($after);
        $action = $isPlaced ? ($wasPlaced ? 'moved' : 'created') : ($wasPlaced ? 'cancelled' : 'updated');
        $event = ['event_key' => $after['key'].':'.$after['revision'].':'.$action,
            'action' => $action, 'actor_id' => (int) $actor->id, 'requester_account_id' => null,
            'team_season_ids' => array_values(array_unique(array_merge($before['team_season_ids'] ?? [], $after['team_season_ids']))),
            'before' => $before, 'after' => $after];
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'pitch_planning', 'object_id' => $record->id,
            'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_value' => json_encode($event, JSON_THROW_ON_ERROR),
        ]);
        if ($wasPlaced || $isPlaced) {
            app(PitchPlanningNotificationService::class)->record($tenant, $event);
        }
    }

    private function placement(Competition $record): array
    {
        return ['date' => $record->plays_on?->toDateString(),
            'starts_at' => $record->starts_at ? Time::parse($record->starts_at)->format('H:i:s') : null,
            'field_id' => $record->field_id ? (int) $record->field_id : null, 'active' => (bool) $record->active];
    }

    private function snapshot(Tenant $tenant, Competition $record): array
    {
        $settings = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $record->id)->first();
        $teams = $this->projection->table('pitch_competition_teams', $tenant)->where('competition_id', $record->id)->orderBy('team_season_id')->pluck('team_season_id')->all();
        $placement = $this->placement($record);
        if ($placement['field_id'] && $placement['starts_at'] && $placement['date']) {
            $entry = $this->projection->competition($record, $settings, $teams);
        } else {
            // Unknown source placement is not midnight or a full-field reservation.
            $entry = ['key' => 'competition:'.$record->id, 'source' => 'competition', 'source_id' => (int) $record->id,
                'original_date' => null, 'date' => $placement['date'], 'starts_at' => $placement['starts_at'] ?? '',
                'ends_at' => '', 'starts_at_iso' => null, 'ends_at_iso' => null,
                'field_id' => $placement['field_id'], 'area_id' => null, 'duration_minutes' => $settings?->duration_minutes ?? 120,
                'team_season_ids' => array_map('intval', $teams), 'status' => 'unplaced',
                'revision' => $record->revision.':'.($settings?->revision ?? 0)];
        }
        if (! $record->active || $settings?->withdrawn) {
            $entry['status'] = 'cancelled';
        }

        return $entry;
    }

    private function occupies(array $entry): bool
    {
        return $entry['status'] === 'approved' && $entry['starts_at_iso'] !== null;
    }

    private function assertTransaction(Tenant $tenant, Competition $record): void
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0
            && (! $record->exists || (int) $record->tenant_id === (int) $tenant->id), 403);
    }

    private function authorize(Account $actor, Tenant $tenant, Competition $before, Competition $after): void
    {
        $frames = [];
        foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
            $frames[$key] = request()->attributes->get($key);
            request()->attributes->remove($key);
        }
        try {
            abort_if(request()->attributes->get('clubunio_impersonation'), 403);
            $fresh = $actor->fresh();
            // Direct editors keep their existing dynamic module grants. Planner-only
            // role restrictions must not silently revoke established editor rights.
            abort_unless($fresh?->active, 403);
            $permissions = app(ModulePermissionService::class);
            $permissions->authorize($fresh, $tenant, 'competitions', $before->exists ? 'update' : 'create');
            if ($before->exists && $before->active !== $after->active) {
                $permissions->authorize($fresh, $tenant, 'competitions', $after->active ? 'restore' : 'deactivate');
            }
        } finally {
            foreach ($frames as $key => $frame) {
                if ($frame !== null) {
                    request()->attributes->set($key, $frame);
                }
            }
        }
    }
}
