<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MobileSeasonScope;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

final class KeycloakSportRole
{
    public function effective(Account $account, Tenant $tenant, object $membership): object
    {
        if (! config('clubunio-verein-oidc.invites_enabled')
            || ! in_array($membership->role, ['trainer', 'technical_lead', 'member'], true)) {
            return $membership;
        }
        $db = DB::connection('clubunio');
        if (! $db->table('clubunio.keycloak_account_links')->where('issuer', config('clubunio-verein-oidc.issuer'))
            ->where('account_id', $account->id)->exists()) {
            return $membership;
        }
        $own = app(MemberAccessService::class)->activeMembers($tenant)
            ->whereIn('clubunio.members.id', $db->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)
                ->where('account_id', $account->id)->where('kind', 'self')->where('state', 'approved')->select('member_id'))
            ->select('clubunio.members.id');
        $assigned = fn (string $table) => app(MobileSeasonScope::class)->teams(
            $db->table('clubunio.'.$table.' as assignment')
                ->join('clubunio.team_seasons as ts', fn ($j) => $j->on('ts.id', 'assignment.team_season_id')->on('ts.tenant_id', 'assignment.tenant_id'))
                ->join('clubunio.teams as t', fn ($j) => $j->on('t.id', 'ts.team_id')->on('t.tenant_id', 'ts.tenant_id'))
                ->where('assignment.tenant_id', $tenant->id)->where('assignment.active', true)
                ->where('ts.active', true)->where('t.active', true)->whereIn('assignment.member_id', $own),
            $tenant, 'ts.season_id',
        );
        $lead = $assigned('technical_leads')->exists();
        $trainer = ! $lead && $assigned('team_staff')
            ->join('clubunio.staff_functions as sf', fn ($j) => $j->on('sf.id', 'assignment.staff_function_id')->on('sf.tenant_id', 'assignment.tenant_id'))
            ->where('sf.active', true)->where(fn ($q) => $q
                ->whereIn(new Expression('upper(trim(sf.code))'), MemberTeamAccessService::TRAINER_CODES)
                ->orWhereIn(new Expression('lower(trim(sf.name))'), MemberTeamAccessService::TRAINER_NAMES))->exists();
        $result = clone $membership;
        $result->role = $lead ? 'technical_lead' : ($trainer ? 'trainer' : 'member');

        return $result;
    }
}
