<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TenantMailSettings
{
    public function replyTo(int $tenantId): ?string
    {
        $value = $this->query($tenantId)->where('enabled', true)->value('encrypted_value');

        return $value === null ? null : self::validAddress(Crypt::decryptString($value));
    }

    public static function validAddress(string $value): ?string
    {
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    public function save(?Account $actor, Tenant $tenant, ?string $value): void
    {
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $value = trim($value ?? '');
        Validator::make(['reply_to' => $value === '' ? null : $value], [
            'reply_to' => ['nullable', 'email', 'max:254', function ($attribute, $value, $fail) {
                if (self::validAddress($value) === null) {
                    $fail(__('mail_delivery.invalid_address'));
                }
            }],
        ], [], ['reply_to' => __('mail_delivery.reply_to')])->validate();

        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $value) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
            $setting = $this->query($tenant->id)->first() ?? new Setting;
            $setting->forceFill([
                'tenant_id' => $tenant->id, 'area' => 'mail', 'key' => 'MAIL_REPLY_TO',
                'label' => 'mail_delivery.reply_to', 'value_type' => 'string', 'is_secret' => false,
                'enabled' => $value !== '', 'encrypted_value' => Crypt::encryptString($value),
            ])->save();
            DB::connection('clubunio')->table('clubunio.setting_changes')->insert([
                'tenant_id' => $tenant->id, 'setting_id' => $setting->id, 'actor_id' => $actor->id,
            ]);
        });
    }

    private function query(int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return Setting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('area', 'mail')->where('key', 'MAIL_REPLY_TO');
    }
}
