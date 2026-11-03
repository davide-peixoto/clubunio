<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Setting, Tenant, TrainingOption};
use Illuminate\Support\Facades\Crypt;

class ActiveTrainingPlan
{
    // Preserve the existing encrypted native-ID value when moving the setting.
    public const KEY = 'SIGNAGE_PERIOD_ID';

    public function period(Tenant $tenant): ?TrainingOption
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('key', self::KEY)
            ->where('area', 'general')->first()
            ?? Setting::where('tenant_id', $tenant->id)->where('key', self::KEY)
                ->where('area', 'signage')->where('enabled', true)->first();
        if (! $row) { return null; }
        try { $value = Crypt::decryptString($row->encrypted_value); }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return null; }
        if (! ctype_digit($value) || (int) $value === 0) { return null; }

        return TrainingOption::where('tenant_id', $tenant->id)->where('category', 'period')
            ->where('active', true)->find((int) $value);
    }
}
