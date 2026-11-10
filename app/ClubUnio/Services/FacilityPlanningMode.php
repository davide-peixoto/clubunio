<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{FieldAvailability, Tenant, TrainingOption, TrainingSlot};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class FacilityPlanningMode
{
    public const KINDS = ['field' => 'Spielfeld', 'hall' => 'Halle', 'court' => 'Court', 'other' => 'Other facility'];

    public static function ready(): bool
    {
        return Schema::connection('clubunio')->hasColumn('clubunio.tenants', 'facility_planning_mode');
    }

    public static function protected(Tenant $tenant): bool
    {
        return $tenant->slug === 'sv-sissach';
    }

    public static function neutral(?Tenant $tenant): bool
    {
        return $tenant && ! self::protected($tenant) && $tenant->getAttribute('facility_planning_mode') === 'neutral';
    }

    // Only the platform service may activate existing tenants, under its tenant lock.
    public static function activate(Tenant $tenant): array
    {
        abort_unless(self::ready() && ! self::protected($tenant) && ! self::neutral($tenant), 403);
        $configured = FieldAvailability::where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('status', fn ($status) => $status->where('active', true))->pluck('field_id');
        $fields = TrainingOption::where('tenant_id', $tenant->id)->where('category', 'field')->where('active', true)->pluck('id');
        if ($fields->diff($configured)->isNotEmpty()
            || TrainingSlot::where('tenant_id', $tenant->id)->where('active', true)
                ->where(fn ($slots) => $slots->whereNull('field_id')->orWhereNotIn('field_id', $fields)
                    ->orWhere('without_main', true)->orWhere('synthetic_only', true))->exists()) {
            throw ValidationException::withMessages(['operation' => __('Before switching, assign active facilities and statuses and review alternative training schedules.')]);
        }
        $tenant->forceFill(['facility_planning_mode' => 'neutral']);

        return ['before' => 'legacy', 'after' => 'neutral'];
    }

    public static function checkUnused(Tenant $tenant, int $field): void
    {
        $db = DB::connection('clubunio');
        $onField = fn ($table) => $db->table('clubunio.'.$table)->where('tenant_id', $tenant->id)->where('field_id', $field);
        $today = now('Europe/Zurich')->toDateString();
        if ($onField('training_slots')->where('active', true)->exists()
            || $onField('competitions')->where('active', true)->where('plays_on', '>=', $today)->exists()
            || (app(PitchPlanningProjection::class)->ready() && (
                $onField('pitch_requests')->where('date', '>=', $today)->whereIn('status', ['pending', 'approved'])->exists()
                || $onField('pitch_training_exceptions')->where('date', '>=', $today)->where('cancelled', false)->exists()
            ))) {
            throw ValidationException::withMessages(['active' => __('Move existing training and bookings before deactivating this facility.')]);
        }
    }
}
