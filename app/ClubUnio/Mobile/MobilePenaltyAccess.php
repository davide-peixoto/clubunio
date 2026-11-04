<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, MobileModuleSettings};
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MobilePenaltyAccess
{
    public function canPlay(Account $actor, Tenant $tenant): bool
    {
        if (! config('clubunio-mobile.native_penalty_enabled', false)) return false;
        try { app(MobileAccess::class)->enter($actor, $tenant); }
        catch (HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; return false; }
        return in_array('Penalty', app(MobileModuleSettings::class)->visibleKeys($actor, $tenant), true);
    }
    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless(config('clubunio-mobile.native_penalty_enabled', false), 503, 'Vereinschallenge noch nicht freigeschaltet.');
        abort_unless($this->canPlay($actor, $tenant), 403);
    }
    public function canReset(Account $actor, Tenant $tenant): bool
    {
        return $this->canPlay($actor, $tenant) && in_array(app(MemberAccessService::class)->membership($actor, $tenant)?->role, ['admin','office'], true);
    }
    public function notice(Account $actor, Tenant $tenant, int $resetId): object
    {
        $this->authorize($actor, $tenant);
        $row = DB::connection('clubunio')->table('clubora.mobile_penalty_resets')->where('tenant_id', $tenant->id)->where('id', $resetId)->first();
        abort_unless($row, 404);
        return $row;
    }
}
