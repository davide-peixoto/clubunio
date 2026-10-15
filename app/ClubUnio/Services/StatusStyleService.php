<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\StatusStyle;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StatusStyleService
{
    private array $colors = [];

    public function colors(Account $actor, Tenant $tenant): array
    {
        $key = $actor->id.':'.$tenant->id;
        if (! array_key_exists($key, $this->colors)) {
            abort_unless($actor->canAccessTenant($tenant), 403);
            $this->colors[$key] = StatusStyle::where('tenant_id', $tenant->id)->where('active', true)->pluck('color', 'value_key')->all();
        }

        return $this->colors[$key];
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): StatusStyle
    {
        abort_unless($actor->canAccessTenant($tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id ? StatusStyle::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new StatusStyle;
            if ($id && (int) ($input['revision'] ?? 0) !== (int) $record->revision) {
                throw ValidationException::withMessages(['revision' => 'Die Farbe wurde geändert. Bitte neu laden.']);
            }
            $rules = ['name' => 'required|string|max:160', 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'active' => 'required|boolean', 'revision' => 'nullable|integer|min:1'];
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['form' => 'Unbekannte oder geschützte Felder.']);
            }
            $data = Validator::make($input, $rules)->validate();
            $data['value_key'] = StatusAppearance::key($data['name']);
            if ($data['value_key'] === '' || StatusStyle::where('tenant_id', $tenant->id)->where('value_key', $data['value_key'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['name' => 'Diese Bezeichnung ist leer oder bereits vorhanden.']);
            }
            unset($data['revision']);
            $before = $id ? $record->only(array_keys($data)) : null;
            $record->forceFill($data + ['tenant_id' => $tenant->id, 'revision' => $id ? $record->revision + 1 : 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'status_styles', 'object_id' => $record->id, 'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);
            $this->colors = [];

            return $record;
        });
    }

    public function seed(Tenant $tenant): int
    {
        $db = DB::connection('clubunio');

        return $db->transaction(function () use ($db, $tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $count = 0;
            foreach (['member_statuses', 'member_change_statuses', 'registration_statuses', 'expense_statuses', 'invoice_statuses', 'facility_statuses', 'training_options', 'member_types', 'genders'] as $table) {
                foreach ($db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->pluck('name') as $name) {
                    $key = StatusAppearance::key($name);
                    if ($key !== '' && ! StatusStyle::where('tenant_id', $tenant->id)->where('value_key', $key)->exists()) {
                        $db->table('clubora.status_styles')->insert(['tenant_id' => $tenant->id, 'name' => $name, 'value_key' => $key, 'color' => StatusAppearance::defaultHex($name)]);
                        $count++;
                    }
                }
            }
            $this->colors = [];

            return $count;
        });
    }
}
