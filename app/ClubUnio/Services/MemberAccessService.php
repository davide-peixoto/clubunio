<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MemberAccessService
{
    public function membership(Account $actor, Tenant $tenant): ?object
    {
        if (! $actor->active) {
            return null;
        }

        return DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->first();
    }

    public function activeMembers(Tenant $tenant): Builder
    {
        $today = now('Europe/Zurich')->toDateString();

        return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhere('exit_date', '>', $today))
            ->whereDoesntHave('status', fn ($q) => $q->where('ends_membership', true));
    }

    public function represented(Account $actor, Tenant $tenant, bool $ownOnly = false): Builder
    {
        $query = $this->activeMembers($tenant);
        if (! $this->membership($actor, $tenant)) {
            return $query->whereRaw('1=0');
        }
        $today = now('Europe/Zurich')->toDateString();

        return $query->whereExists(function ($q) use ($actor, $tenant, $ownOnly, $today) {
            $q->selectRaw('1')->from('clubora.account_member_links as access_link')
                ->where('access_link.tenant_id', $tenant->id)->where('access_link.account_id', $actor->id)
                ->where('access_link.state', 'approved')->whereColumn('access_link.member_id', 'clubora.members.id')
                ->where(function ($q) use ($ownOnly, $today) {
                    $q->where('access_link.kind', 'self');
                    if (! $ownOnly) {
                        $q->orWhere(fn ($q) => $q->where('access_link.kind', 'parent')->where('birth_date', '<=', $today)
                            ->whereRaw("birth_date + interval '18 years' > CAST(? AS date)", [$today]));
                    }
                });
        });
    }

    public function canEnter(Account $actor, Tenant $tenant): bool
    {
        $membership = $this->membership($actor, $tenant);
        if (! $membership) {
            return false;
        }

        return in_array($membership->role, ['admin', 'office'], true)
            || ($membership->portal_enabled && $this->represented($actor, $tenant)->exists());
    }

    public function responsibleTeams(Account $actor, Tenant $tenant): array
    {
        $role = $this->membership($actor, $tenant)?->role;
        if (! in_array($role, ['trainer', 'technical_lead'], true) || ! $this->canEnter($actor, $tenant)) {
            return [];
        }
        $table = $role === 'trainer' ? 'team_staff' : 'technical_leads';

        return DB::connection('clubunio')->table('clubora.'.$table.' as assignment')
            ->join('clubora.team_seasons as season_team', function ($join) {
                $join->on('season_team.id', '=', 'assignment.team_season_id')->on('season_team.tenant_id', '=', 'assignment.tenant_id');
            })->join('clubora.teams as team', function ($join) {
                $join->on('team.id', '=', 'season_team.team_id')->on('team.tenant_id', '=', 'season_team.tenant_id');
            })->where('assignment.tenant_id', $tenant->id)->where('assignment.active', true)
            ->where('season_team.active', true)->where('team.active', true)
            ->whereIn('assignment.member_id', $this->represented($actor, $tenant, true)->select('clubora.members.id'))
            ->distinct()->pluck('assignment.team_season_id')->all();
    }

    public function visibleMembers(Account $actor, Tenant $tenant): Builder
    {
        if (! $this->canEnter($actor, $tenant)) {
            return $this->activeMembers($tenant)->whereRaw('1=0');
        }
        if (in_array($this->membership($actor, $tenant)->role, ['admin', 'office'], true)) {
            return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id);
        }
        $teams = $this->responsibleTeams($actor, $tenant);
        $tables = $this->membership($actor, $tenant)->role === 'technical_lead' ? ['team_players', 'team_staff'] : ['team_players'];

        return $this->activeMembers($tenant)->where(function ($q) use ($actor, $tenant, $teams, $tables) {
            $q->whereIn('clubora.members.id', $this->represented($actor, $tenant)->select('clubora.members.id'));
            foreach ($tables as $table) {
                $q->orWhereIn('clubora.members.id', DB::connection('clubunio')->table('clubora.'.$table)
                    ->where('tenant_id', $tenant->id)->where('active', true)->whereIn('team_season_id', $teams)->select('member_id'));
            }
        });
    }
}
