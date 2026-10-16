<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

class MembershipReportService
{
    public function members(Account $actor, Tenant $tenant): Builder
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'reports', 'view');

        return app(MemberAccessService::class)->activeMembers($tenant)
            ->whereHas('status', fn ($q) => $q->where('restores_membership', true));
    }

    public function passive(Account $actor, Tenant $tenant): Builder
    {
        return $this->members($actor, $tenant)->whereHas('type', fn ($q) => $q->where('passive_membership', true));
    }

    public function teamCandidates(Account $actor, Tenant $tenant): Builder
    {
        $query = $this->members($actor, $tenant)->whereHas('type', fn ($q) => $q->where('team_required', true));
        foreach (['honorary', 'free_membership', 'supporter', 'sponsor'] as $flag) {
            $query->where(fn ($q) => $q->where($flag, false)->orWhereNull($flag));
        }

        return $query;
    }

    public function withoutTeam(Builder $query, Tenant $tenant, mixed $season): Builder
    {
        if (! is_scalar($season) || ! ctype_digit((string) $season)
            || ! Season::where('tenant_id', $tenant->id)->whereKey($season)->exists()) {
            return $query->whereRaw('1=0');
        }

        return $query->whereDoesntHave('teamPlayers', fn ($q) => $q->where('active', true)
            ->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $season)));
    }

    public function currentSeason(Tenant $tenant): ?int
    {
        $today = now('Europe/Zurich')->toDateString();
        $ids = Season::where('tenant_id', $tenant->id)->where('active', true)
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->sole() : null;
    }
}
