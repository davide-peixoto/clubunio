<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamAssignmentService
{
    /** Create new season assignments together, preserving model events, audit and duplicate guards. */
    public function createMany(Account $actor, Tenant $tenant, string $model, array $inputs): Collection
    {
        abort_unless(in_array($model, [TeamPlayer::class, TeamStaff::class, TechnicalLead::class], true), 403);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'create');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $inputs) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'create');
            $fields = ['member_id', 'team_season_id', 'active'];
            if ($model === TeamStaff::class) {
                $fields[] = 'staff_function_id';
            }
            $rules = ['rows' => 'present|array|max:2000', 'rows.*' => 'array:'.implode(',', $fields),
                'rows.*.member_id' => 'required|integer|min:1', 'rows.*.team_season_id' => 'required|integer|min:1', 'rows.*.active' => 'required|boolean'];
            if ($model === TeamStaff::class) {
                $rules['rows.*.staff_function_id'] = 'required|integer|min:1';
            }
            $rows = Validator::make(['rows' => array_values($inputs)], $rules)->validate()['rows'];
            $db = DB::connection('clubunio');
            $members = array_fill_keys($db->table('clubora.members')->where('tenant_id', $tenant->id)->whereNull('archived_at')
                ->whereIn('id', array_column($rows, 'member_id'))->pluck('id')->all(), true);
            $teams = array_fill_keys($db->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('active', true)
                ->whereIn('id', array_column($rows, 'team_season_id'))->pluck('id')->all(), true);
            $functions = $model === TeamStaff::class ? array_fill_keys($db->table('clubora.staff_functions')->where('tenant_id', $tenant->id)->where('active', true)
                ->whereIn('id', array_column($rows, 'staff_function_id'))->pluck('id')->all(), true) : [];
            $identity = fn ($row) => $row['team_season_id'].':'.$row['member_id'].':'.($row['staff_function_id'] ?? '');
            $seen = [];
            foreach ($model::where('tenant_id', $tenant->id)->where('active', true)->whereIn('team_season_id', array_keys($teams))->get($fields) as $existing) {
                $seen[$identity($existing->only($fields))] = true;
            }
            foreach ($rows as $row) {
                if (! isset($members[$row['member_id']], $teams[$row['team_season_id']]) || ($model === TeamStaff::class && ! isset($functions[$row['staff_function_id']]))) {
                    throw ValidationException::withMessages(['plan' => 'Eine Zuordnung hat keine gültigen Vereinsreferenzen.']);
                }
                if ($row['active']) {
                    $key = $identity($row);
                    if (isset($seen[$key])) {
                        throw ValidationException::withMessages(['plan' => 'Diese aktive Zuordnung besteht bereits.']);
                    }
                    $seen[$key] = true;
                }
            }
            $records = collect();
            $audit = [];
            foreach ($rows as $row) {
                $record = new $model;
                $record->forceFill($row + ['tenant_id' => $tenant->id])->save();
                $records->push($record);
                $audit[] = ['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id,
                    'before_value' => null, 'after_value' => json_encode($row, JSON_THROW_ON_ERROR)];
            }
            foreach (array_chunk($audit, 100) as $chunk) {
                $db->table('clubora.object_changes')->insert($chunk);
            }
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'create');

            return $records;
        });
    }

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
