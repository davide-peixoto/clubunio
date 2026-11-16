<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;

class ErpNextConfiguration
{
    private const KEY = 'ERPNEXT_CONNECTION';

    public static function canManage(?Account $actor, ?Tenant $tenant): bool
    {
        return $actor && $tenant && $actor->fresh()?->active
            && $actor->tenants()->whereKey($tenant->id)->wherePivot('role', 'admin')->exists();
    }

    public function connection(Tenant $tenant): array
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'erpnext')->where('key', self::KEY)->first();

        return $row ? json_decode(Crypt::decryptString($row->encrypted_value), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function verifiedConnection(Tenant $tenant): array
    {
        try {
            $config = $this->connection($tenant);
        } catch (DecryptException|JsonException) {
            abort(409, trans('erpnext.errors.destination'));
        }
        abort_unless($config && ($config['origin'] ?? null) === $this->origin(), 409, trans('erpnext.errors.destination'));

        return $config;
    }

    public function origin(): string
    {
        $origin = rtrim((string) config('clubunio-erpnext.origin'), '/');
        abort_unless(config('clubunio-erpnext.enabled')
            && preg_match('/^https:\/\/[a-z0-9.-]+$/iD', $origin), 409, trans('erpnext.errors.origin'));

        return $origin;
    }

    public function reference(Tenant $tenant, string $type, int $id): string
    {
        $namespace = (string) config('clubunio-erpnext.namespace');
        abort_unless(preg_match('/^[A-Za-z0-9-]{1,32}$/D', $namespace), 409, trans('erpnext.errors.origin'));

        return "clubunio:{$namespace}:{$tenant->id}:{$type}:{$id}";
    }

    public function save(Account $actor, Tenant $tenant, array $data): void
    {
        abort_unless(self::canManage($actor, $tenant), 403);
        $data = Validator::make($data, [
            'company' => 'required|string|max:140',
            'company_address' => 'required|string|max:140',
            'territory' => 'required|string|max:140',
            'customer_group' => 'required|string|max:140',
            'item_code' => 'required|string|max:140',
            'income_account' => 'required|string|max:140',
            'supplier_group' => 'nullable|required_with:expense_item_code,expense_account|string|max:140',
            'expense_item_code' => 'nullable|required_with:expense_account|string|max:140',
            'expense_account' => 'nullable|required_with:expense_item_code|string|max:140',
            'due_days' => 'required|integer|min:0|max:365',
            'api_key' => ['required', 'regex:/^[A-Za-z0-9_-]{8,128}$/D'],
            'api_secret' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,128}$/D'],
        ], [], array_map(fn ($key) => __("erpnext.fields.$key"), array_combine(array_keys($data), array_keys($data))))->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::canManage($actor, $tenant), 403);
            $old = $this->connection($tenant);
            $origin = $this->origin();
            abort_if($old && ($origin !== $old['origin'] || $data['company'] !== $old['company'])
                && (ErpNextInvoiceTransferService::query($tenant)->exists() || ErpNextExpenseTransferService::query($tenant)->exists()),
                409, trans('erpnext.errors.destination'));
            abort_if(empty($data['api_secret']) && $data['api_key'] !== ($old['api_key'] ?? ''), 422, trans('erpnext.errors.credentials'));
            $data['api_secret'] = ($data['api_secret'] ?? '') ?: ($old['api_secret'] ?? '');
            abort_if($data['api_secret'] === '', 422, trans('erpnext.errors.credentials'));
            $data['origin'] = $origin;
            $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'erpnext')->where('key', self::KEY)->first() ?? new Setting;
            $setting->forceFill([
                'tenant_id' => $tenant->id, 'area' => 'erpnext', 'key' => self::KEY, 'label' => 'ERPNext',
                'encrypted_value' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'enabled' => true, 'is_secret' => true, 'value_type' => 'string',
            ])->save();
            DB::connection('clubunio')->table('clubora.setting_changes')->insert([
                'tenant_id' => $tenant->id, 'setting_id' => $setting->id, 'actor_id' => $actor->id,
            ]);
        });
    }
}
