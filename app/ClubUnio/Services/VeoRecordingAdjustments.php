<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class VeoRecordingAdjustments
{
    public function __construct(private VeoRecordingPlan $plan, private PitchPlanningProjection $projection) {}

    public function canManage(Account $actor, Tenant $tenant): bool
    {
        return ! request()->attributes->has('clubunio_impersonation')
            && $actor->hasUnrestrictedBackofficeAccess($tenant)
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'competitions', 'update');
    }

    public function current(Tenant $tenant, int $matchId): array
    {
        $match = $this->projection->table('sfv_schedule', $tenant)->where('match_id', $matchId)->first();
        abort_unless($match?->starts_at, 404);
        $date = Time::parse($match->starts_at)->tz('Europe/Zurich')->toDateString();
        $day = Time::parse($date, 'Europe/Zurich');
        $entry = collect($this->plan->entries($tenant, $day->subDays(8)->toDateString(), $day->addDays(8)->toDateString()))->firstWhere('match_id', $matchId);
        if (! $entry) {
            $adjustment = $this->projection->table('veo_recording_adjustments', $tenant)->where('match_id', $matchId)->first();
            if ($adjustment) {
                $date = Time::parse($adjustment->starts_at)->tz('Europe/Zurich')->toDateString();
                $entry = collect($this->plan->entries($tenant, $date, $date))->firstWhere('match_id', $matchId);
            }
        }
        abort_unless($entry, 404);

        return $entry;
    }

    public function save(Account $actor, Tenant $tenant, int $matchId, array $input, bool $reset = false): void
    {
        abort_unless($this->canManage($actor, $tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $matchId, $input, $reset) {
            // Serialize camera moves for this club, including previously unadjusted fixtures.
            DB::connection('clubunio')->table('clubunio.tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canManage($actor, $tenant), 403);
            $entry = $this->current($tenant, $matchId);
            abort_unless(isset($input['revision']) && hash_equals($entry['revision'], (string) $input['revision']), 409, trans('veo_calendar.stale'));
            $query = $this->projection->table('veo_recording_adjustments', $tenant)->where('match_id', $matchId);
            $before = $query->first();
            if ($reset) {
                if (! $before) { return; }
                $query->delete();
                $after = ['reset' => true];
            } else {
                $data = Validator::make($input, [
                    'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i',
                    'duration' => 'required|integer|min:15|max:360', 'field_id' => 'required|integer|min:1',
                ], [], ['date' => trans('veo_calendar.date'), 'time' => trans('veo_calendar.time'),
                    'duration' => trans('veo_calendar.duration'), 'field_id' => trans('veo_calendar.camera')])->validate();
                $start = Time::parse($data['date'].' '.$data['time'], 'Europe/Zurich');
                if ($start->format('Y-m-d H:i') !== $data['date'].' '.$data['time']) {
                    throw ValidationException::withMessages(['form.time' => trans('validation.date', ['attribute' => trans('veo_calendar.time')])]);
                }
                abort_if(abs($start->diffInDays($entry['fixture_starts_at'])) > 7, 422, trans('veo_calendar.range_error'));
                abort_unless($this->projection->table('training_options', $tenant)->where('id', $data['field_id'])
                    ->where('category', 'field')->where('active', true)->exists(), 404);
                abort_unless($this->plan->cameras($tenant)->has($data['field_id']), 422, trans('veo_calendar.camera_required'));
                $end = $start->addMinutes($data['duration']);
                foreach ($this->plan->entries($tenant, $start->subDay()->toDateString(), $end->addDay()->toDateString()) as $other) {
                    if ($other['match_id'] !== $matchId && $other['field_id'] === (int) $data['field_id'] && $other['camera'] !== null
                        && $start->lt($other['ends_at']) && $end->gt($other['starts_at'])) {
                        throw ValidationException::withMessages(['form.time' => trans('veo_calendar.overlap')]);
                    }
                }
                $after = ['tenant_id' => $tenant->id, 'match_id' => $matchId, 'field_id' => (int) $data['field_id'],
                    'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
                    'basis_hash' => $entry['basis_hash'], 'revision' => ($before?->revision ?? 0) + 1,
                    'fixture_starts_at' => $entry['fixture_starts_at']->toIso8601String(),
                    'updated_by' => $actor->id, 'updated_at' => now()->toIso8601String()];
                $query->upsert($after, ['tenant_id', 'match_id'], array_diff(array_keys($after), ['tenant_id', 'match_id']));
            }
            $this->projection->table('object_changes', $tenant)->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'veo_recording', 'object_id' => $matchId,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($after, JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
