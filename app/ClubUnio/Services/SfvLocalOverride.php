<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

final class SfvLocalOverride
{
    public static function allowsAt(Time $kickoff, Time $now): bool
    {
        return $now->gte($kickoff->subHour()) && $now->lt($kickoff);
    }

    public static function recorded(Tenant $tenant, object $competition): ?array
    {
        $payload = DB::connection('clubunio')->table('clubora.object_changes')
            ->where('tenant_id', $tenant->id)->where('object_type', 'sfv_local_override')
            ->where('object_id', $competition->id)->orderByDesc('id')->value('after_value');
        $override = $payload ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : null;

        return $override && $override['placement'] === [
            Time::parse($competition->plays_on)->toDateString(), substr((string) $competition->starts_at, 0, 5), (int) $competition->field_id,
        ]
            && Time::now()->lt(Time::parse($override['until']))
            ? $override : null;
    }
}
