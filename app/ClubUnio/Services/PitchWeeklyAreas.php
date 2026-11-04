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
            $data['area_id'] = (int) ($data['field_id'] ?? 0) === (int) $record->field_id ? $record->area_id : null;
        }
        $data['area_id'] = empty($data['area_id']) ? null : (int) $data['area_id'];
        if ($data['area_id'] && ! $this->matches($tenant->id, $data['field_id'] ?? null, $data['area_id'])) $this->invalid();
        return $data;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['area_id' => __('Das ausgewählte Spielfeld oder die Teilfläche ist nicht verfügbar.')]);
    }
}
