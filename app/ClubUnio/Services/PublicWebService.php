<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class PublicWebService
{
    public function enabled(Tenant $tenant, string $surface): bool
    {
        $key = match ($surface) {
            'signage' => 'SIGNAGE_PUBLIC_ENABLED', 'registration' => 'REGISTRATION_PUBLIC_ENABLED',
            default => throw new \InvalidArgumentException('Unknown public surface'),
        };
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'public')->where('key', $key)->where('enabled', true)->first();
        try {
            return $row && Crypt::decryptString($row->encrypted_value) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public function authorize(Tenant $tenant, string $surface): void
    {
        abort_unless($this->enabled($tenant, $surface), 404);
    }
}
