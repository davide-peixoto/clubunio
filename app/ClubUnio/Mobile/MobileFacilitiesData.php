<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\ModulePermissionService;
use Illuminate\Support\Facades\DB;

final class MobileFacilitiesData
{
    public function read(Account $actor, Tenant $tenant): array
    {
        $permissions = app(ModulePermissionService::class);
        $permissions->authorize($actor, $tenant, 'field_availability', 'view');
        $db = DB::connection('clubunio');
        $statuses = $db->table('clubora.facility_statuses')->where('tenant_id', $tenant->id)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $labels = $db->table('clubora.training_options')->where('tenant_id', $tenant->id)->where('category', 'field')->where('active', true)->pluck('name', 'id');
        $canEdit = $permissions->allows($actor, $tenant, 'field_availability', 'update');
        $rows = $db->table('clubora.field_availabilities')->where('tenant_id', $tenant->id)->where('active', true)->whereIn('field_id', $labels->keys())->orderBy('field_id')->get()
            ->map(fn ($field) => ['id' => (int) $field->id, 'field_id' => (int) $field->field_id, 'revision' => (int) $field->revision,
                'name' => $labels[$field->field_id], 'note' => $field->note ?? '', 'status_id' => (int) $field->status_id,
                'status_name' => $statuses->firstWhere('id', $field->status_id)?->name ?? 'Unbekannt',
                'blocks_training' => $statuses->firstWhere('id', $field->status_id)?->blocks_training, 'can_edit' => $canEdit])->all();
        return ['fields' => $rows, 'statuses' => $statuses->where('active', true)->map(fn ($status) => ['id' => (int) $status->id,
            'revision' => (int) $status->revision, 'name' => $status->name, 'blocks_training' => (bool) $status->blocks_training])->values()->all()];
    }
}
