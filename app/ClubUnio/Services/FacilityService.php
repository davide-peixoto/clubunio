<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Models\FieldAvailability;
use App\ClubUnio\Models\FootballClub;
use App\ClubUnio\Models\SignageNotice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FacilityService
{
    public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input)
    {
        $module = ModulePermissionService::modelModule($model);
        abort_unless($module, 403);
        app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $id ? 'update' : 'create');
        if (! in_array($model, [Competition::class, FacilityStatus::class, FieldAvailability::class, SignageNotice::class, FootballClub::class], true)) {
            throw new \InvalidArgumentException('Unsupported facility object');
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $model, $id, $input, $module) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $id ? 'update' : 'create');
            $record = $id ? $model::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new $model;
            if ($model === FieldAvailability::class && $id && ! app(ModulePermissionService::class)->allows($actor, $tenant, 'facilities', 'update')) {
                // Narrow caretakers may change status/note, not move or deactivate the field record.
                abort_if((array_key_exists('field_id', $input) && (int) $input['field_id'] !== (int) $record->field_id)
                    || (array_key_exists('active', $input) && (bool) $input['active'] !== (bool) $record->active), 403);
                $input['field_id'] = $record->field_id;
                $input['active'] = $record->active;
            }
            if ($id && array_key_exists('active', $input) && (bool) $record->active !== (bool) $input['active']) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $input['active'] ? 'restore' : 'deactivate');
            }
            if ($model === SignageNotice::class || ($model === Competition::class && ! app(ModulePermissionService::class)->isProfile($actor, $tenant) && array_key_exists('show_on_signage', $input)
                && (! $id || (bool) $record->show_on_signage !== (bool) $input['show_on_signage']))) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'signage', 'send');
            }
            if ($id && (int) ($input['revision'] ?? 0) !== (int) $record->revision) {
                throw ValidationException::withMessages(['revision' => 'Der Datensatz wurde geändert. Bitte neu laden.']);
            }
            $rules = ['active' => 'required|boolean', 'revision' => $id ? 'required|integer|min:1' : 'nullable|integer|min:1'];
            $option = fn ($category, $required = false) => [$required ? 'required' : 'nullable', 'integer', Rule::exists('clubunio.clubora.training_options', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('category', $category)->where('active', true))];
            if ($model === Competition::class) {
                $rules += ['plays_on' => 'required|date_format:Y-m-d', 'starts_at' => 'nullable|date_format:H:i:s', 'host_name' => 'required|string|max:255', 'guest_name' => 'required|string|max:255', 'host_category' => 'nullable|string|max:100', 'guest_category' => 'nullable|string|max:100', 'host_club_number' => 'nullable|integer|min:1', 'guest_club_number' => 'nullable|integer|min:1', 'remarks' => 'nullable|string|max:10000', 'show_on_signage' => 'required|boolean', 'field_id' => $option('field')];
                foreach (['host', 'guest'] as $side) {
                    $rules[$side.'_club_number'] = 'nullable|integer|min:1|max:2147483647';
                    $rules[$side.'_room_id'] = $option('room');
                    $rules[$side.'_side_id'] = $option('side');
                }
                if (preg_match('/^\d{2}:\d{2}$/', (string) ($input['starts_at'] ?? ''))) {
                    $input['starts_at'] .= ':00';
                }
            } elseif ($model === FootballClub::class) {
                $rules += ['name' => 'required|string|max:255', 'logo_enabled' => 'required|boolean'];
                if (! $id) {
                    $rules['club_number'] = ['bail', 'required', 'integer', 'min:1', 'max:2147483647', Rule::unique('clubunio.clubora.football_clubs')->where('tenant_id', $tenant->id)];
                }
            } elseif ($model === SignageNotice::class) {
                $rules += ['shows_on' => 'required|date_format:Y-m-d', 'title' => 'required|string|max:255', 'body' => 'required|string|max:10000'];
            } elseif ($model === FieldAvailability::class) {
                $rules += ['field_id' => $option('field', true), 'status_id' => ['required', 'integer', Rule::exists('clubunio.clubora.facility_statuses', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true))], 'note' => 'nullable|string|max:500'];
            } else {
                $rules += ['name' => 'required|string|max:160', 'blocks_training' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:10000'];
                if (! $id) {
                    $rules['code'] = ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('clubunio.clubora.facility_statuses')->where(fn ($q) => $q->where('tenant_id', $tenant->id))];
                }
            }
            // Source identifiers and sync timestamps never come from an edit form.
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['form' => 'Unbekannte oder geschützte Felder.']);
            }
            $data = Validator::make($input, $rules)->validate();
            if ($model === Competition::class) {
                foreach (['host_club_number', 'guest_club_number'] as $field) {
                    // Historical unknown/inactive numbers may remain, but cannot be assigned anew.
                    if (! empty($data[$field]) && (! $id || (int) $data[$field] !== (int) $record->$field)
                        && ! FootballClub::where('tenant_id', $tenant->id)->where('club_number', $data[$field])->where('active', true)->exists()) {
                        throw ValidationException::withMessages([$field => 'Bitte einen aktiven Verein aus dem Vereinsstamm auswählen.']);
                    }
                }
            }
            if ($model === FieldAvailability::class && FieldAvailability::where('tenant_id', $tenant->id)->where('field_id', $data['field_id'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['field_id' => 'Für diesen Platz ist bereits ein Anlagenstatus vorhanden.']);
            }
            unset($data['revision']);
            if ($model === Competition::class && app(ModulePermissionService::class)->isProfile($actor, $tenant)
                && (($id && $record->show_on_signage) || $data['show_on_signage'])
                && (clone $record)->forceFill($data)->isDirty(array_keys($data))) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'signage', 'send');
            }
            $before = $id ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id, 'revision' => $id ? $record->revision + 1 : 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => str($record->getTable())->afterLast('.')->toString(), 'object_id' => $record->id, 'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);

            return $record;
        });
    }
}
