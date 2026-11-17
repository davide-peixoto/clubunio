<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MobileSeasonScope;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainerWebSeasonScope
{
    public function applies(Account $actor, Tenant $tenant): bool
    {
        $request = request();
        if ($request->attributes->get('clubunioWorkspace')
            || ! $request->routeIs('clubunio.portal.*', 'clubunio.planning.*', 'clubunio.training.*', 'clubunio.bus.*')
            || (string) auth('portal')->id() !== (string) $actor->id) {
            return false;
        }

        return app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'trainer';
    }

    public function seasonRecords($query, Account $actor, Tenant $tenant, string $column = 'season_id')
    {
        return $this->applies($actor, $tenant) ? app(MobileSeasonScope::class)->teams($query, $tenant, $column) : $query;
    }

    public function teamRecords($query, Account $actor, Tenant $tenant, string $column = 'team_season_id')
    {
        if ($this->applies($actor, $tenant)) {
            $query->whereIn($column, $this->currentTeams($tenant)->select('id'));
        }

        return $query;
    }

    public function currentTeams(Tenant $tenant)
    {
        return app(MobileSeasonScope::class)->teams(
            DB::connection('clubunio')->table('clubora.team_seasons')->where('tenant_id', $tenant->id),
            $tenant,
        );
    }

    public function sfvMatches($query, Account $actor, Tenant $tenant)
    {
        if (! $this->applies($actor, $tenant)) {
            return $query;
        }
        $season = app(MobileSeasonScope::class)->current($tenant);
        if (! $season?->starts_on || ! $season?->ends_on) {
            return $query->whereRaw('1=0');
        }

        return $query->where('starts_at', '>=', CarbonImmutable::parse($season->starts_on, 'Europe/Zurich')->toIso8601String())
            ->where('starts_at', '<', CarbonImmutable::parse($season->ends_on, 'Europe/Zurich')->addDay()->toIso8601String());
    }

    public function absences($query, Account $actor, Tenant $tenant)
    {
        if (! $this->applies($actor, $tenant)) {
            return $query;
        }
        $season = app(MobileSeasonScope::class)->current($tenant);

        return $season?->starts_on && $season?->ends_on
            ? $query->where('starts_on', '<=', $season->ends_on)->where('ends_on', '>=', $season->starts_on)
            : $query->whereRaw('1=0');
    }

    public function validateAbsence(Account $actor, Tenant $tenant, string $startsOn, string $endsOn): void
    {
        if ($this->applies($actor, $tenant)) {
            $season = app(MobileSeasonScope::class)->current($tenant);
            if (! $season?->starts_on || ! $season?->ends_on || $startsOn > $season->ends_on || $endsOn < $season->starts_on) {
                throw ValidationException::withMessages(['starts_on' => trans('portal-shell.absence_current_season')]);
            }
        }
    }
}
