<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamAssignmentService
{
    public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input): CatalogModel
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', $id ? 'update' : 'create');
        if (! in_array($model, [TeamSeason::class, TeamPlayer::class, TeamStaff::class, TechnicalLead::class], true)) {
            throw new \InvalidArgumentException('Unsupported team object');
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id ? $model::where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', $id ? 'update' : 'create');
            if ($id && array_key_exists('active', $input) && (bool) $record->active !== (bool) $input['active']) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', $input['active'] ? 'restore' : 'deactivate');
            }
            $rules = ['active' => 'required|boolean', '_version' => $id ? 'required|string|size:64' : 'prohibited',
                'tenant_id' => 'prohibited', 'id' => 'prohibited', 'legacy_id' => 'prohibited', 'legacy_invoice_position_id' => 'prohibited'];
            $references = $model === TeamSeason::class ? ['team_id' => 'teams', 'season_id' => 'seasons'] : ['member_id' => 'members', 'team_season_id' => 'team_seasons'];
            if ($model === TeamStaff::class) {
                $references['staff_function_id'] = 'staff_functions';
            }
            foreach ($references as $field => $table) {
                $rules[$field] = [$id && $field === 'staff_function_id' && $record->$field === null ? 'nullable' : 'required', 'integer', Rule::exists('clubunio.clubora.'.$table, 'id')->where(function ($q) use ($tenant, $table, $record, $field) {
                    $q->where('tenant_id', $tenant->id)->where(function ($q) use ($table, $record, $field) {
                        $table === 'members' ? $q->whereNull('archived_at') : $q->where('active', true);
                        $q->orWhere('id', $record->$field ?? -1);
                    });
                })];
            }
            $data = Validator::make($input, $rules)->validate();
            if ($id && ! hash_equals(FamilyService::version($record), $data['_version'])) {
                throw ValidationException::withMessages(['active' => 'Datensatz wurde inzwischen geändert. Bitte erneut öffnen.']);
            }
            if ($id && ($model === TeamSeason::class || ($model === TeamPlayer::class && $record->legacy_invoice_position_id))) {
                foreach (array_keys($references) as $field) {
                    if ((int) $data[$field] !== (int) $record->$field) {
                        throw ValidationException::withMessages([$field => 'Bestehende Verknüpfung bleibt erhalten. Zuordnung deaktivieren und neu erfassen.']);
                    }
                }
            }
            if ($data['active']) {
                $duplicate = $model::where('tenant_id', $tenant->id)->where('active', true)->when($id, fn ($q) => $q->whereKeyNot($id));
                foreach (array_keys($references) as $field) {
                    $duplicate->where($field, $data[$field]);
                }
                // Preserve existing historical duplicates, but never create another.
                $changed = ! $id || ! $record->active;
                foreach (array_keys($references) as $field) {
                    $changed = $changed || (int) $record->$field !== (int) $data[$field];
                }
                if ($changed && $duplicate->exists()) {
                    throw ValidationException::withMessages([array_key_first($references) => 'Diese aktive Zuordnung besteht bereits.']);
                }
            }
            $data = array_intersect_key($data, array_flip(array_merge(['active'], array_keys($references))));
            if ($model === TeamSeason::class) {
                $data['label'] = DB::connection('clubunio')->table('clubora.teams')->where('tenant_id', $tenant->id)->where('id', $data['team_id'])->value('name').' / '.DB::connection('clubunio')->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('id', $data['season_id'])->value('name');
            }
            $before = $record->exists ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id]);
            if (! $record->exists || $record->isDirty()) {
                $record->save();
                DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id,
                    'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);
            }

            return $record;
        });
    }
}
