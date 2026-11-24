<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Setting;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;

class ReactUi
{
    public const KEY = 'REACT_ENABLED';

    public function enabled(?int $tenantId = null): bool
    {
        $tenantId ??= Filament::getTenant()?->id;
        if (! $tenantId) { return false; }
        $cache = 'clubunio.react.enabled.'.$tenantId;
        if (! request()->attributes->has($cache)) {
            $value = $this->encryptedValue($tenantId);
            $enabled = false;
            if ($value !== null) {
                try { $enabled = Crypt::decryptString($value) === '1'; }
                catch (\Illuminate\Contracts\Encryption\DecryptException) { /* Preserve the existing UI on an invalid parameter. */ }
            }
            request()->attributes->set($cache, $enabled);
        }

        return request()->attributes->get($cache);
    }

    protected function encryptedValue(int $tenantId): ?string
    {
        return Setting::where('tenant_id', $tenantId)->where('area', 'general')->where('key', self::KEY)
            ->where('is_secret', false)->value('encrypted_value');
    }

    public function forget(int $tenantId): void
    {
        request()->attributes->remove('clubunio.react.enabled.'.$tenantId);
    }
}
