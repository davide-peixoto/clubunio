<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyService
{
    public static function version(CatalogModel $record): string
    {
        return hash('sha256', json_encode($record->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input): CatalogModel
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'families', $id ? 'view' : 'create');
        if (! in_array($model, [Family::class, FamilyMember::class], true)) {
            throw new \InvalidArgumentException('Unsupported family object');
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id ? $model::where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            $rules = ['active' => 'required|boolean', '_version' => $id ? 'required|string|size:64' : 'prohibited',
                'tenant_id' => 'prohibited', 'id' => 'prohibited', 'legacy_id' => 'prohibited'];
            if ($model === Family::class) {
                $rules += ['name' => 'required|string|max:160', 'street' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
                    'postal_code' => 'nullable|string|max:20', 'country_id' => ['nullable', 'integer', Rule::exists('clubunio.clubora.countries', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record->country_id ?? -1)))]];
            } else {
                foreach (['family_id' => 'families', 'member_id' => 'members'] as $field => $table) {
                    $rules[$field] = ['required', 'integer', Rule::exists('clubunio.clubora.'.$table, 'id')->where(function ($q) use ($tenant, $table, $record, $field) {
                        $q->where('tenant_id', $tenant->id)->where(function ($q) use ($table, $record, $field) {
                            $table === 'members' ? $q->whereNull('archived_at') : $q->where('active', true);
                            $q->orWhere('id', $record->$field ?? -1);
                        });
                    })];
                }
                $rules += ['discount' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2', 'notes' => 'nullable|string|max:4000'];
            }
            $data = Validator::make($input, $rules)->validate();
            $statusChanged = $id && (bool) $record->active !== (bool) $data['active'];
            if ($statusChanged) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'families', $data['active'] ? 'restore' : 'deactivate');
            }
            $candidate = clone $record;
            $candidate->forceFill(array_diff_key($data, array_flip(['_version', 'active', 'tenant_id', 'id', 'legacy_id'])));
            if (! $statusChanged || $candidate->isDirty()) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'families', $id ? 'update' : 'create');
            }
            if ($id && ! hash_equals(self::version($record), $data['_version'])) {
                throw ValidationException::withMessages([$model === Family::class ? 'name' : 'notes' => 'Datensatz wurde inzwischen geändert. Bitte erneut öffnen.']);
            }
            if ($model === FamilyMember::class && FamilyMember::where('tenant_id', $tenant->id)->where('family_id', $data['family_id'])->where('member_id', $data['member_id'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['member_id' => 'Dieses Mitglied ist bereits zugeordnet. Bestehende Zuordnung bearbeiten.']);
            }
            $data = array_diff_key($data, array_flip(['_version', 'tenant_id', 'id', 'legacy_id']));
            $before = $record->exists ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id]);
            if (! $record->exists || $record->isDirty()) {
                $record->save();
                DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id, 'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);
            }

            return $record;
        });
    }
}
