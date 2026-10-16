<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccessProfileService
{
    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): AccessProfile
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAdministrationService::class)->allowed($actor, $tenant), 403);
            if (array_diff(array_keys($input), ['name', 'description', 'active', 'permissions', 'revision', 'reason'])) {
                throw ValidationException::withMessages(['name' => 'Unbekannte oder geschützte Felder.']);
            }
            if (is_string($input['name'] ?? null)) {
                $input['name'] = trim($input['name']);
            }
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:120', Rule::unique('clubunio.clubora.access_profiles', 'name')->where('tenant_id', $tenant->id)->ignore($id)],
                'description' => 'nullable|string|max:2000', 'active' => 'required|boolean',
                'permissions' => 'present|array', 'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::keys())],
                'revision' => $id ? 'required|integer|min:1' : 'nullable|integer|max:0',
                'reason' => 'required|string|min:10|max:500',
            ])->validate();
            $record = $id ? AccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new AccessProfile;
            if ($id && $record->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['name' => 'Profil wurde inzwischen geändert. Bitte erneut öffnen.']);
            }
            foreach ($data['permissions'] as $permission) {
                $view = explode('.', $permission)[0].'.view';
                if (! in_array($view, $data['permissions'], true)) {
                    throw ValidationException::withMessages(['permissions' => 'Eine Funktion benötigt auch das Anzeigerecht ihres Moduls.']);
                }
            }
            $before = $id ? $record->only(['name', 'description', 'permissions', 'active', 'revision']) : null;
            $record->forceFill(['tenant_id' => $tenant->id, 'name' => trim($data['name']), 'description' => $data['description'] ?? null,
                'permissions' => array_values($data['permissions']), 'active' => $data['active'], 'revision' => ($record->revision ?? 0) + 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'access_profiles', 'object_id' => $record->id,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($record->only(['name', 'description', 'permissions', 'active', 'revision']) + ['reason' => $data['reason']], JSON_THROW_ON_ERROR),
            ]);

            return $record;
        });
    }
}
