<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SignageBrandService
{
    public const KEYS = ['color' => 'SIGNAGE_BRAND_COLOR', 'logo' => 'SIGNAGE_BRAND_LOGO', 'title' => 'SIGNAGE_BRAND_TITLE'];

    public const LABELS = ['color' => 'Vereinsfarbe', 'logo' => 'Vereinslogo', 'title' => 'Signage-Titel'];

    public function read(Tenant $tenant): array
    {
        $fallback = $tenant->slug === 'sv-sissach' ? '#cf1032' : '#164b8a';
        $data = ['color' => $fallback, 'logo' => $tenant->slug === 'sv-sissach' ? '/images/svs-logo.png' : '/brand/blue-v1/clubunio-symbol.svg', 'title' => $tenant->name, 'period_id' => 0];
        foreach (Setting::where('tenant_id', $tenant->id)->where('area', 'signage')->where('enabled', true)->whereIn('key', self::KEYS)->get() as $row) {
            try {
                $data[array_search($row->key, self::KEYS, true)] = Crypt::decryptString($row->encrypted_value);
            } catch (\Throwable) {
                // A broken design value must not take the display offline.
            }
        }
        $data['color'] = preg_match('/^#[a-f0-9]{6}$/iD', $data['color']) ? $data['color'] : $fallback;
        $rgb = array_map(fn ($hex) => hexdec($hex) / 255, str_split(substr($data['color'], 1), 2));
        $rgb = array_map(fn ($value) => $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4, $rgb);
        $data['ink'] = $rgb[0] * .2126 + $rgb[1] * .7152 + $rgb[2] * .0722 > .179 ? '#16191e' : '#ffffff';
        $data['logo'] = $this->safeLogo($data['logo'], $tenant) ? $data['logo'] : '/brand/blue-v1/clubunio-symbol.svg';
        $data['title'] = mb_substr(trim($data['title']), 0, 100) ?: $tenant->name;
        $data['period_id'] = app(ActiveTrainingPlan::class)->period($tenant)?->id ?? 0;

        return $data;
    }

    public function version(Tenant $tenant): string
    {
        return hash('sha256', json_encode($this->read($tenant), JSON_THROW_ON_ERROR));
    }

    private function safeLogo(string $url, ?Tenant $tenant = null): bool
    {
        if (str_starts_with($url, '/club-logo/')) {
            return $tenant && preg_match('~^/club-logo/'.$tenant->id.'/[a-f0-9]{64}\.png$~D', $url);
        }
        if (preg_match('~^/(images|brand)/[a-zA-Z0-9_./-]+\.(png|jpg|jpeg|webp|svg)$~D', $url) && ! str_contains($url, '..')) {
            return true;
        }
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['query']) && ! isset($parts['fragment']) && ! isset($parts['port'])
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    public function validateDesign(array $input, ?Tenant $tenant = null): array
    {
        $data = Validator::make($input, ['color' => ['required', 'regex:/^#[a-f0-9]{6}$/iD'],
            'logo' => 'required|string|max:2048', 'title' => 'required|string|max:100'])->validate();
        if (! $this->safeLogo($data['logo'], $tenant)) {
            throw ValidationException::withMessages(['logo' => __('Öffentliche HTTPS-Bildadresse ohne Zugangsdaten/Query oder lokales Vereinslogo verwenden.')]);
        }

        return $data;
    }

    public function save(Account $actor, Tenant $tenant, array $input): void
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        $data = Validator::make($input, ['color' => ['required', 'regex:/^#[a-f0-9]{6}$/iD'], 'logo' => 'required|string|max:2048',
            'title' => 'required|string|max:100', 'version' => 'required|string|size:64',
            'period_id' => 'prohibited'])->validate();
        if (! $this->safeLogo($data['logo'], $tenant)) {
            throw ValidationException::withMessages(['logo' => __('Öffentliche HTTPS-Bildadresse ohne Zugangsdaten/Query oder lokales Bild unter /images bzw. /brand verwenden.')]);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            if (! hash_equals($this->version($tenant), $data['version'])) {
                throw ValidationException::withMessages(['title' => __('Vereinsdesign wurde inzwischen geändert. Bitte neu öffnen.')]);
            }
            foreach (self::KEYS as $field => $key) {
                $row = Setting::where('tenant_id', $tenant->id)->where('area', 'signage')->where('key', $key)->first() ?? new Setting;
                $row->forceFill(['tenant_id' => $tenant->id, 'area' => 'signage', 'key' => $key, 'label' => self::LABELS[$field],
                    'enabled' => true, 'is_secret' => false, 'value_type' => 'string',
                    'encrypted_value' => Crypt::encryptString((string) ($data[$field] ?? 0))])->save();
                DB::connection('clubunio')->table('clubunio.setting_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'setting_id' => $row->id]);
            }
        });
    }
}
