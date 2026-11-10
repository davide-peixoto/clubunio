<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, MobileModuleSettings, ModulePermissionService};
use App\ClubUnio\Services\FacilityPlanningMode;
use Illuminate\Support\Facades\DB;

final class MobileFacilitiesData
{
    public function canRead(Account $actor, Tenant $tenant): bool
    {
        // Personal status visibility never grants facility administration rights.
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'field_availability', 'view')
            || (app(MemberAccessService::class)->canEnter($actor, $tenant)
                && in_array('Platzverfugbarkeit', app(MobileModuleSettings::class)->visibleKeys($actor, $tenant), true));
    }

    public function read(Account $actor, Tenant $tenant): array
    {
        $permissions = app(ModulePermissionService::class);
        abort_unless($this->canRead($actor, $tenant), 403);
        $db = DB::connection('clubunio');
        $statuses = $db->table('clubunio.facility_statuses')->where('tenant_id', $tenant->id)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $neutral = FacilityPlanningMode::neutral($tenant);
        $labels = $db->table('clubunio.training_options')->where('tenant_id', $tenant->id)->where('category', 'field')->where('active', true)
            ->when($neutral, fn ($query) => $query->orderBy('sort_order')->orderBy('id'))->pluck('name', 'id');
        $statusById = ($neutral ? $statuses->where('active', true) : $statuses)->keyBy('id');
        $canEdit = $permissions->allows($actor, $tenant, 'field_availability', 'update');
        $rows = $db->table('clubunio.field_availabilities')->where('tenant_id', $tenant->id)->where('active', true)->whereIn('field_id', $labels->keys())->orderBy('field_id')->get();
        if ($neutral) {
            $order = $labels->keys()->flip();
            $rows = $rows->sortBy(fn ($field) => $order[$field->field_id])->values();
        }
        $rows = $rows->map(function ($field) use ($labels, $statusById, $canEdit, $neutral) {
            $status = $statusById->get($field->status_id);
            return ['id' => (int) $field->id, 'field_id' => (int) $field->field_id, 'revision' => (int) $field->revision,
                'name' => $labels[$field->field_id], 'note' => $field->note ?? '', 'status_id' => (int) $field->status_id,
                'status_name' => $status?->name ?? ($neutral ? __('Unbekannt') : 'Unbekannt'),
                'blocks_training' => $status ? (bool) $status->blocks_training : null, 'can_edit' => $canEdit];
        })->all();
        return ['fields' => $rows, 'statuses' => $statuses->where('active', true)->map(fn ($status) => ['id' => (int) $status->id,
            'revision' => (int) $status->revision, 'name' => $status->name, 'blocks_training' => (bool) $status->blocks_training])->values()->all()];
    }
}
