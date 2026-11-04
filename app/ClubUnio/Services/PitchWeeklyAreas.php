<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Tenant, TrainingSlot};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Optional weekly metadata; legacy training remains usable before migration 001700. */
class PitchWeeklyAreas
{
    public function ready(): bool
    {
        return Schema::connection('clubunio')->hasColumn('clubora.training_slots', 'area_id')
            && Schema::connection('clubunio')->hasTable('clubora.pitch_areas');
    }

    public function options(Tenant $tenant, ?int $field): array
    {
        if (! $field || ! $this->ready()) return [];
        return DB::connection('clubunio')->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)
            ->where('field_id', $field)->orderBy('id')->get()->mapWithKeys(fn ($area) => [$area->id => $this->label($area)])->all();
    }

    public function label(object $area): string
    {
        return (string) (app()->getLocale() === 'en' ? ($area->name_en ?: $area->name_de) : ($area->name_de ?: $area->name_en));
    }

    /** Zero is a form-only request for a half; null remains an explicit whole pitch. */
    public function trainingOptions(Tenant $tenant, ?int $field): array
    {
        return [0 => __('Halber Platz (automatisch)')] + $this->options($tenant, $field);
    }

    public function suggestedHalf(Tenant $tenant, array $data, ?int $exclude = null): ?int
    {
        if (empty($data['field_id'])) return null;
        $areas = DB::connection('clubunio')->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)
            ->where('field_id', $data['field_id'])->orderBy('id')->get()->keyBy('id');
        $halves = $areas->filter(fn ($area) => abs((float)$area->width * (float)$area->height - .5) < .000001);
        if ($halves->isEmpty()) throw ValidationException::withMessages(['area_id' => __('Für diesen Platz ist noch keine halbe Teilfläche eingerichtet.')]);
        $season = DB::connection('clubunio')->table('clubora.team_seasons')->where('tenant_id', $tenant->id)
            ->where('id', $data['team_season_id'] ?? 0)->value('season_id');
        $slots = DB::connection('clubunio')->table('clubora.training_slots as slot')
            ->join('clubora.team_seasons as ts', fn ($j) => $j->on('ts.id', '=', 'slot.team_season_id')->on('ts.tenant_id', '=', 'slot.tenant_id'))
            ->where('slot.tenant_id', $tenant->id)->where('slot.field_id', $data['field_id'])->where('ts.season_id', $season)
            ->where('slot.period_id', $data['period_id'] ?? null)->where('slot.weekday_id', $data['weekday_id'] ?? null)
            ->where('slot.active', true)->where('ts.active', true)->where('slot.id', '<>', $exclude ?? 0)
            ->where('slot.starts_at', '<', $data['ends_at'] ?? '00:00:00')->where('slot.ends_at', '>', $data['starts_at'] ?? '24:00:00')
            ->where(function ($q) use ($data) {
                $q->whereRaw('1=0');
                foreach (['all_fields', 'without_main', 'synthetic_only'] as $variant) if (!empty($data[$variant])) $q->orWhere('slot.'.$variant, true);
            })->get(['slot.area_id', 'slot.starts_at', 'slot.ends_at']);
        $scores = [];
        foreach ($halves as $half) {
            $scores[$half->id] = 0;
            foreach ($slots as $slot) {
                $other = $areas->get($slot->area_id);
                if (!$other || ($half->x < $other->x + $other->width && $other->x < $half->x + $half->width
                    && $half->y < $other->y + $other->height && $other->y < $half->y + $half->height)) {
                    $scores[$half->id] += min(strtotime($data['ends_at']), strtotime($slot->ends_at))
                        - max(strtotime($data['starts_at']), strtotime($slot->starts_at));
                }
            }
        }
        // When both halves are occupied, retain a visible conflict rather than inventing capacity.
        asort($scores, SORT_NUMERIC);
        return (int)array_key_first($scores);
    }

    public function matches(int $tenant, ?int $field, ?int $area): bool
    {
        return $field && $area && DB::connection('clubunio')->table('clubora.pitch_areas')
            ->where('tenant_id', $tenant)->where('field_id', $field)->where('id', $area)->exists();
    }

    public function forSave(Tenant $tenant, TrainingSlot $record, array $data): array
    {
        if (! $this->ready()) {
            if (! empty($data['area_id'])) $this->invalid();
            unset($data['area_id']);
            return $data;
        }
        // Older callers preserve a default on ordinary edits, but never carry it to another field.
        if (! array_key_exists('area_id', $data)) {
            $data['area_id'] = $record->exists && (int) ($data['field_id'] ?? 0) === (int) $record->field_id ? $record->area_id : 0;
        }
        if ($data['area_id'] === 0 || $data['area_id'] === '0') $data['area_id'] = $this->suggestedHalf($tenant, $data, $record->id);
        $data['area_id'] = empty($data['area_id']) ? null : (int) $data['area_id'];
        if ($data['area_id'] && ! $this->matches($tenant->id, $data['field_id'] ?? null, $data['area_id'])) $this->invalid();
        return $data;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['area_id' => __('Das ausgewählte Spielfeld oder die Teilfläche ist nicht verfügbar.')]);
    }
}
