<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;

/** Access preview only: ClubUnio must not issue physical access codes during migration. */
class BusKeyboxAccessService
{
    public function inspect(Account $actor, Tenant $tenant, int $id): array
    {
        $bus = app(BusReservationService::class);
        $row = $bus->visible($actor, $tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        $from = Time::parse($row->starts_at)->tz('Europe/Zurich')->subHours(4);
        $until = Time::parse($row->ends_at)->tz('Europe/Zurich')->addHours(4);
        $eligible = $row->status === 'GENEHMIGT' && $bus->table($tenant, 'buses')->where('id', $row->bus_id)->where('active', true)->exists();
        $inWindow = now()->gte($from) && now()->lt($until);

        return ['eligible' => $eligible, 'inWindow' => $eligible && $inWindow, 'from' => $from, 'until' => $until, 'issuanceEnabled' => false];
    }
}
