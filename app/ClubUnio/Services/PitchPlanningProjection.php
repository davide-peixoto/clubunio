<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Competition, FieldAvailability, Tenant, TrainingOption, TrainingSlot};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class PitchPlanningProjection
{
    public function ready(): bool
    {
        $tables = Schema::connection('clubunio')->getTableListing('clubora', false);
        return array_diff(['pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks', 'pitch_competition_settings', 'pitch_competition_teams', 'pitch_planning_events'], $tables) === [];
    }

    public function table(string $table, Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id);
    }

    public function fields(Tenant $tenant): array
    {
        $availability = FieldAvailability::where('tenant_id', $tenant->id)->where('active', true)->with('status')->get()->keyBy('field_id');
        $areas = $this->table('pitch_areas', $tenant)->orderBy('id')->get()->groupBy('field_id');
        return TrainingOption::where('tenant_id', $tenant->id)->where('category', 'field')->where('active', true)->orderBy('id')->get()->map(function ($field) use ($availability, $areas) {
            $status = $availability->get($field->id)?->status;
            return ['id' => (int) $field->id, 'name' => app(LookupTranslations::class)->label('training_options', $field, null, $field->tenant_id),
                'available' => $status && $status->active && ! $status->blocks_training,
                'areas' => ($areas->get($field->id) ?? collect())->map(fn ($area) => $this->area($area))->all()];
        })->all();
    }

    public function area(object $area): array
    {
        return ['id' => (int) $area->id, 'field_id' => (int) $area->field_id,
            'name' => app()->getLocale() === 'en' ? $area->name_en : $area->name_de,
            'name_de' => $area->name_de, 'name_en' => $area->name_en,
            'x' => (float) $area->x, 'y' => (float) $area->y, 'width' => (float) $area->width, 'height' => (float) $area->height,
            'revision' => (string) $area->revision];
    }

    public function entries(Tenant $tenant, string $from, string $to, ?int $period = null): array
    {
        $entries = [];
        $queryFrom = Time::parse($from)->subDay()->toDateString();
        $period ??= app(ActiveTrainingPlan::class)->period($tenant)?->id;
        $variant = app(SignageService::class)->trainingVariant($tenant);
        if ($period) {
            $slots = TrainingSlot::where('tenant_id', $tenant->id)->where('active', true)->where('period_id', $period)
                ->where($variant, true)->with(['weekday', 'teamSeason.team', 'teamSeason.season'])->get();
            // Both interval ends matter: an original outside the visible range may move
            // into it, while an outgoing exception must suppress its original occurrence.
            $exceptions = $this->table('pitch_training_exceptions', $tenant)->whereIn('training_slot_id', $slots->pluck('id'))
                ->where(fn ($query) => $query->whereBetween('original_date', [$queryFrom, $to])->orWhereBetween('date', [$queryFrom, $to]))->get();
            foreach ($slots as $slot) {
                $dates = [];
                for ($day = Time::parse($queryFrom); $day->toDateString() <= $to; $day = $day->addDay()) $dates[] = $day->toDateString();
                $own = $exceptions->where('training_slot_id', $slot->id)->keyBy('original_date');
                foreach ($own as $exception) if ($exception->date >= $queryFrom && $exception->date <= $to) $dates[] = $exception->original_date;
                foreach (array_unique($dates) as $original) {
                    if (! $this->occurs($slot, $original)) continue;
                    $exception = $own->get($original);
                    if ($exception?->cancelled) continue;
                    $entry = $this->training($slot, $original, $exception);
                    if ($entry) $entries[] = $entry;
                }
            }
        }
        $settings = $this->table('pitch_competition_settings', $tenant)->get()->keyBy('competition_id');
        $teams = $this->table('pitch_competition_teams', $tenant)->get()->groupBy('competition_id');
        foreach (Competition::where('tenant_id', $tenant->id)->where('active', true)->whereBetween('plays_on', [$queryFrom, $to])->get() as $competition) {
            $setting = $settings->get($competition->id);
            if ($setting?->withdrawn || ! $competition->field_id || ! $competition->starts_at) continue;
            $entry = $this->competition($competition, $setting, ($teams->get($competition->id) ?? collect())->pluck('team_season_id')->all());
            $entries[] = $entry;
        }
        foreach (['request' => 'pitch_requests', 'block' => 'pitch_blocks'] as $source => $table) {
            $start = $source === 'block' ? Time::parse($from)->subDays(31)->toDateString() : $queryFrom;
            foreach ($this->table($table, $tenant)->whereBetween('date', [$start, $to])->get() as $row) $entries[] = $this->stored($source, $row);
        }
        $start = Time::parse($from, 'Europe/Zurich')->startOfDay()->timestamp;
        $end = Time::parse($to, 'Europe/Zurich')->addDay()->startOfDay()->timestamp;
        return array_values(array_filter($entries, fn ($entry) => strtotime($entry['starts_at_iso']) < $end && strtotime($entry['ends_at_iso']) > $start));
    }

    public function occurs(TrainingSlot $slot, string $date): bool
    {
        $team = $slot->teamSeason;
        $season = $team?->season;
        return $team && $team->active && $team->team?->active && $season?->active
            && substr((string) $season->starts_on, 0, 10) <= $date && substr((string) $season->ends_on, 0, 10) >= $date
            && $slot->weekday?->active && (int) $slot->weekday->code === Time::parse($date)->dayOfWeek + 1;
    }

    public function training(TrainingSlot $slot, string $original, ?object $exception = null): ?array
    {
        if (! $slot->starts_at || ! $slot->ends_at || (! $slot->field_id && ! $exception)) return null;
        $duration = (int) Time::parse($slot->starts_at)->diffInMinutes(Time::parse($slot->ends_at), false);
        if ($duration <= 0) $duration += 1440;
        return $this->entry('training', $slot->id, $exception?->date ?? $original,
            $exception?->starts_at ?? $slot->starts_at, $exception?->duration_minutes ?? $duration,
            $exception?->field_id ?? $slot->field_id, $exception?->area_id,
            [$slot->team_season_id], $slot->teamSeason?->team?->name ?? '', 'approved',
            $slot->revision.':'.($exception?->revision ?? 0), $original);
    }

    public function competition(Competition $row, ?object $settings, array $teams): array
    {
        $area = $settings?->area_id;
        // Legacy source editors can change a field without touching planner metadata.
        // A stale partial allocation then conservatively occupies the entire new field.
        if ($area && ! DB::connection('clubunio')->table('clubora.pitch_areas')->where('tenant_id', $row->tenant_id)
            ->where('id', $area)->where('field_id', $row->field_id)->exists()) $area = null;
        return $this->entry('competition', $row->id, $row->plays_on->toDateString(), $row->starts_at,
            $settings?->duration_minutes ?? 120, $row->field_id, $area, $teams,
            $row->host_name.' - '.$row->guest_name, $settings?->withdrawn ? 'cancelled' : 'approved', $row->revision.':'.($settings?->revision ?? 0));
    }

    /** Signage consumes effective occurrences without modifying the weekly models. */
    public function trainingModels(Tenant $tenant, string $date, int $period): Collection
    {
        $entries = collect($this->entries($tenant, $date, $date, $period))->where('source', 'training')->where('date', $date);
        $slots = TrainingSlot::where('tenant_id', $tenant->id)->whereIn('id', $entries->pluck('source_id'))->with('teamSeason.team')->get()->keyBy('id');
        return $entries->map(function ($entry) use ($slots) {
            $slot = clone $slots[$entry['source_id']];
            $slot->field_id = $entry['field_id'];
            $slot->starts_at = $entry['starts_at'];
            $slot->ends_at = $entry['ends_at'];
            return $slot;
        })->sortBy('starts_at')->values();
    }

    public function stored(string $source, object $row): array
    {
        $entry = $this->entry($source, $row->id, $row->date, $row->starts_at, $row->duration_minutes, $row->field_id, $row->area_id,
            $source === 'request' ? [$row->team_season_id] : [], $source === 'request' ? $row->opponent : $row->reason,
            $row->status, (string) $row->revision);
        if ($source === 'request') $entry['requester_account_id'] = (int) $row->requester_account_id;
        return $entry;
    }

    public function entry(string $source, int $id, string $date, string $time, int $duration, int $field, ?int $area, array $teams, string $title, string $status, string $revision, ?string $original = null): array
    {
        $start = Time::parse($date.' '.$time, 'Europe/Zurich');
        return ['key' => $source.':'.$id.($original ? ':'.$original : ''), 'source' => $source, 'source_id' => $id,
            'original_date' => $original, 'date' => $date, 'starts_at' => $start->format('H:i'),
            'ends_at' => $start->addMinutes($duration)->format('H:i'), 'duration_minutes' => $duration,
            'starts_at_iso' => $start->toIso8601String(), 'ends_at_iso' => $start->addMinutes($duration)->toIso8601String(),
            'field_id' => $field, 'area_id' => $area, 'team_season_ids' => array_map('intval', $teams),
            'title' => $title, 'status' => $status, 'revision' => $revision, 'editable' => false, 'conflicts' => []];
    }
}
