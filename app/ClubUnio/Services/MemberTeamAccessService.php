<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, Tenant};
use App\ClubUnio\Mobile\MobileTeamsData;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Expression;

/** Explicit MyTeams context; never grants a global directory or financial permission. */
final class MemberTeamAccessService
{
    public const TRAINER_CODES = ['TRAINER', 'ASSISTANT_TRAINER', 'TRAINER_ASSISTANT'];
    public const TRAINER_NAMES = ['trainer', 'assistenztrainer'];

    public static function trainerFunction(?object $function): bool
    {
        return $function !== null && (in_array(strtoupper(trim($function->code ?? '')), self::TRAINER_CODES, true)
            || in_array(mb_strtolower(trim($function->name ?? '')), self::TRAINER_NAMES, true));
    }

    public function mode(Account $actor, Tenant $tenant, int $team): string
    {
        app(MobileTeamsData::class)->team($actor, $tenant, $team);
        return $this->forAuthorizedTeam($actor, $tenant, $team);
    }

    /** Only for a projection whose caller already resolved the fresh team scope. */
    public function forAuthorizedTeam(Account $actor, Tenant $tenant, int $team): string
    {
        $access = app(MemberAccessService::class);
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view')) return 'full';
        $role = $access->membership($actor, $tenant)?->role;
        if ($role === 'trainer' && $this->trainerTeams($actor, $tenant)->where('assignment.team_season_id', $team)->exists()) return 'trainer';
        if ($role === 'technical_lead' && in_array($team, array_map('intval', $access->responsibleTeams($actor, $tenant)), true)) return 'lead';
        return 'parent';
    }

    public function trainerTeams(Account $actor, Tenant $tenant)
    {
        $access = app(MemberAccessService::class);
        $query = DB::connection('clubunio')->table('clubora.team_staff as assignment')
            ->join('clubora.staff_functions as sf', fn($join) => $join->on('sf.id','=','assignment.staff_function_id')->on('sf.tenant_id','=','assignment.tenant_id'))
            ->join('clubora.team_seasons as ts', fn($join) => $join->on('ts.id','=','assignment.team_season_id')->on('ts.tenant_id','=','assignment.tenant_id'))
            ->join('clubora.teams as t', fn($join) => $join->on('t.id','=','ts.team_id')->on('t.tenant_id','=','ts.tenant_id'))
            ->where('assignment.tenant_id',$tenant->id)->where('assignment.active',true)->where('ts.active',true)->where('t.active',true)
            ->where('sf.active',true)->whereIn('assignment.member_id',$access->represented($actor,$tenant,true)->select('clubora.members.id'))
            ->where(fn($query) => $query->whereIn(new Expression('upper(trim(sf.code))'),self::TRAINER_CODES)
                ->orWhereIn(new Expression('lower(trim(sf.name))'),self::TRAINER_NAMES));
        if ($access->membership($actor,$tenant)?->role !== 'trainer') $query->whereRaw('1=0');
        return app(\App\ClubUnio\Mobile\MobileSeasonScope::class)->teams($query,$tenant,'ts.season_id')->select('assignment.team_season_id');
    }

    /** Default member reads must not inherit the broad helper/team_staff scope. */
    public function visibleTrainerContacts(Account $actor, Tenant $tenant)
    {
        $access=app(MemberAccessService::class);
        return $access->activeMembers($tenant)->where(function($query) use($access,$actor,$tenant) {
            $query->whereIn('clubora.members.id',$access->represented($actor,$tenant)->select('clubora.members.id'));
            foreach (['team_players','team_staff'] as $table) $query->orWhereIn('clubora.members.id',DB::connection('clubunio')->table('clubora.'.$table)
                ->where('tenant_id',$tenant->id)->where('active',true)->whereIn('team_season_id',$this->trainerTeams($actor,$tenant))->select('member_id'));
        });
    }

    public function roster(Tenant $tenant, int $team, bool $staffOnly = false)
    {
        $staff = DB::connection('clubunio')->table('clubora.team_staff')->where('tenant_id', $tenant->id)
            ->where('team_season_id', $team)->where('active', true)->select('member_id');
        return app(MemberAccessService::class)->activeMembers($tenant)->where(function ($query) use ($tenant, $team, $staff, $staffOnly) {
            $query->whereIn('clubora.members.id', $staff);
            if (!$staffOnly) $query->orWhereIn('clubora.members.id', DB::connection('clubunio')->table('clubora.team_players')
                ->where('tenant_id', $tenant->id)->where('team_season_id', $team)->where('active', true)->select('member_id'));
        });
    }

    public function member(Account $actor, Tenant $tenant, int $team, int $member): array
    {
        $mode = $this->mode($actor, $tenant, $team);
        return [$this->roster($tenant, $team, $mode === 'parent')->findOrFail($member), $mode];
    }

    public function requestable(Account $actor, Tenant $tenant, int $team, int $member, bool $lock = false): Member
    {
        // Discard a pre-lock season read before the authoritative write/review check.
        request()->attributes->remove('mobile.current_season.'.$tenant->id.'.'.now('Europe/Zurich')->toDateString());
        // Re-evaluated inside the tenant write lock and again when an office approves.
        abort_unless($this->mode($actor, $tenant, $team) === 'trainer', 403);
        $query = $this->roster($tenant, $team);
        if ($lock) $query->lockForUpdate();
        return $query->findOrFail($member);
    }
}
