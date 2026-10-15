<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    public function enableInstagram(Account $actor, Tenant $tenant, int $id, bool $enabled, bool $expected): void
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $enabled, $expected) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'integrations')->where('key', 'INSTAGRAM_ACCESS_TOKEN')->lockForUpdate()->findOrFail($id);
            if ((bool) $setting->enabled !== $expected) {
                throw ValidationException::withMessages(['enabled' => 'Einstellung wurde geändert. Bitte neu laden.']);
            }
            if ($enabled && trim(Crypt::decryptString($setting->encrypted_value)) === '') {
                throw ValidationException::withMessages(['enabled' => 'Instagram-Zugang fehlt.']);
            }
            $setting->forceFill(['enabled' => $enabled])->save();
            DB::connection('clubunio')->table('clubora.setting_changes')->insert(['tenant_id' => $tenant->id, 'setting_id' => $id, 'actor_id' => $actor->id]);
        });
    }

    public function update(Account $actor, Tenant $tenant, int $id, string $value): void
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $value) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $setting = Setting::where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($setting->area === 'public') {
                abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            }
            abort_if($setting->key === BexioConnectionService::CONNECTION_KEY, 403);
            if ($setting->area === 'bexio' && in_array($setting->key, ['BEXIO_CLIENT_ID', 'BEXIO_CLIENT_SECRET', 'BEXIO_AUTHORIZE_SCOPES'], true)) {
                abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
            }
            $rule = match ($setting->value_type) {
                'integer' => 'integer','boolean' => 'in:0,1',default => 'string'
            };
            Validator::make(['value' => $value], ['value' => ['required', $rule, 'max:65535']])->validate();
            $setting->forceFill(['encrypted_value' => Crypt::encryptString($value)])->save();
            DB::connection('clubunio')->table('clubora.setting_changes')->insert([
                'tenant_id' => $tenant->id, 'setting_id' => $setting->id, 'actor_id' => $actor->id,
            ]);
        });
    }
}
