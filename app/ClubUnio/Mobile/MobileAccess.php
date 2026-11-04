<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\DB;

final class MobileAccess
{
    public function enter(Account $actor, Tenant $tenant): void
    {
        $fresh = app(MobileReadContext::class)->freshActor($actor);
        abort_unless($fresh?->active && ! $fresh->api_only
            && app(MemberAccessService::class)->canEnter($fresh, $tenant), 403);
    }

    public function admin(Account $actor, Tenant $tenant): bool
    {
        $this->enter($actor, $tenant);

        return app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'admin';
    }

    public function teams(Account $actor, Tenant $tenant, bool $staffOnly = false)
    {
        $this->enter($actor, $tenant);
        $access = app(MemberAccessService::class);
        $query = DB::connection('clubunio')->table('clubora.team_seasons as ts')
            ->join('clubora.teams as t', fn ($join) => $join->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))
            ->join('clubora.seasons as s', fn ($join) => $join->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
            ->where('ts.tenant_id', $tenant->id)->where('ts.active', true)->where('t.active', true);
        app(MobileSeasonScope::class)->teams($query, $tenant, 'ts.season_id');
        if (! $this->admin($actor, $tenant)) {
            $responsible = $access->responsibleTeams($actor, $tenant);
            $own = $access->represented($actor, $tenant, $staffOnly)->select('clubora.members.id');
            $query->where(function ($q) use ($tenant, $responsible, $own, $staffOnly) {
                $q->whereIn('ts.id', $responsible);
                foreach ($staffOnly ? ['team_staff'] : ['team_players', 'team_staff'] as $table) {
                    $q->orWhereIn('ts.id', DB::connection('clubunio')->table('clubora.'.$table)
                        ->where('tenant_id', $tenant->id)->where('active', true)->whereIn('member_id', $own)->select('team_season_id'));
                }
            });
        }

        return $query->select(['ts.id', 'ts.team_id', 'ts.season_id', 'ts.label', 't.name', 's.name as season']);
    }

    public function invoice(Account $actor, Tenant $tenant, int $id)
    {
        $this->enter($actor, $tenant);
        // Financial data is NEVER widened by trainer/TL roster visibility.
        $query = app(\App\ClubUnio\Services\InvoiceAccessService::class)->visible($actor, $tenant);
        if (! $this->admin($actor, $tenant)) {
            $query->whereIn('member_id', app(MemberAccessService::class)->represented($actor, $tenant)->select('clubora.members.id'));
        }

        return $query->whereKey($id)->firstOrFail();
    }

    /** Directional discovery, one bounded SQL projection; never authorize each row in PHP. */
    public function contacts(Account $actor, Tenant $tenant, string $search = '', int $limit = 40): array
    {
        $query = $this->contactCandidates($actor, $tenant);
        if (($search = trim($search)) !== '') {
            // Treat user input as text, not LIKE wildcards; bindings protect the SQL boundary.
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->whereRaw("lower(candidate.display_name) LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }
        return $query->orderBy('candidate.display_name')->orderBy('candidate.id')->limit(max(1, min(40, $limit)))
            ->get(['candidate.id', 'candidate.display_name as name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all();
    }

    /** Same fresh directed policy for creation and ongoing direct-chat access. */
    public function canContact(Account $actor, Account $other, Tenant $tenant): bool
    {
        if (!request()->isMethod('GET')) request()->attributes->remove('mobile.current_season.'.$tenant->id.'.'.now('Europe/Zurich')->toDateString());
        if ($this->contactCandidates($actor, $tenant)->where('candidate.id', $other->id)->exists()) return true;
        // Reply is not discovery: an already established active pair can answer while
        // either directed entitlement still exists. No stale chat membership grants rights.
        $ids = [(int) $actor->id, (int) $other->id]; sort($ids);
        $chat = DB::connection('clubunio')->table('clubora.chat_conversations as direct')
            ->where('direct.tenant_id', $tenant->id)->where('direct.kind', 'direct')
            ->where('direct.scope_key', 'direct:'.implode(':', $ids))
            ->whereRaw('(select count(*) from clubora.chat_memberships cm where cm.tenant_id = direct.tenant_id and cm.conversation_id = direct.id and cm.left_at is null) = 2');
        foreach ($ids as $id) $chat->whereExists(fn ($q) => $q->selectRaw('1')->from('clubora.chat_memberships as pair')
            ->whereColumn('pair.tenant_id', 'direct.tenant_id')->whereColumn('pair.conversation_id', 'direct.id')
            ->where('pair.account_id', $id)->whereNull('pair.left_at'));
        if (! $chat->exists()) return false;
        try {
            return $this->contactCandidates($other, $tenant)->where('candidate.id', $actor->id)->exists();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return false;
        }
    }

    private function contactCandidates(Account $actor, Tenant $tenant)
    {
        $this->enter($actor, $tenant);
        $access = app(MemberAccessService::class);
        $role = $access->membership($actor, $tenant)?->role;
        $query = DB::connection('clubunio')->table('clubora.accounts as candidate')
            ->join('clubora.tenant_accounts as membership', fn ($j) => $j->on('membership.account_id', '=', 'candidate.id')->where('membership.tenant_id', $tenant->id))
            ->where('candidate.id', '<>', $actor->id)->where('candidate.active', true)->where('candidate.api_only', false)
            ->where('membership.role', '<>', 'profile')
            ->where(function ($q) use ($tenant) {
                $q->whereIn('membership.role', ['admin', 'office'])->orWhere(function ($q) use ($tenant) {
                    $q->where('membership.portal_enabled', true)->whereExists($this->accountLinks($tenant, 'candidate.id'));
                });
            })
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.chat_blocks as block')->where('block.tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('block.account_id', $actor->id)->whereColumn('block.other_account_id', 'candidate.id'))
                    ->orWhere(fn ($q) => $q->whereColumn('block.account_id', 'candidate.id')->where('block.other_account_id', $actor->id))));
        if (in_array($role, ['admin', 'office', 'technical_lead'], true)) return $query;

        $own = $access->represented($actor, $tenant, true)->pluck('clubora.members.id')->all();
        $children = $access->represented($actor, $tenant)->whereExists(fn ($q) => $q->selectRaw('1')->from('clubora.account_member_links as child_link')
            ->where('child_link.tenant_id', $tenant->id)->where('child_link.account_id', $actor->id)
            ->where('child_link.kind', 'parent')->where('child_link.state', 'approved')->whereColumn('child_link.member_id', 'clubora.members.id'))
            ->pluck('clubora.members.id')->all();
        // Flatten only this operation's freshly authorized actor facts. Deeply
        // correlated family/team subqueries otherwise multiply work per candidate.
        $playerTeams = $own ? $this->contactTeams($tenant, $own, ['team_players'])->pluck('contact_team.id')->all() : [];
        $childTeams = $children ? $this->contactTeams($tenant, $children, ['team_players'])->pluck('contact_team.id')->all() : [];
        $trainerTeams = $role === 'trainer' && $own ? app(\App\ClubUnio\Services\MemberTeamAccessService::class)->trainerTeams($actor, $tenant)->pluck('assignment.team_season_id')->all() : [];
        $targets = $access->activeMembers($tenant)->where(function ($q) use ($tenant, $role, $playerTeams, $childTeams, $trainerTeams) {
            foreach (['team_players', 'team_staff'] as $table) {
                $q->orWhereIn('clubora.members.id', $this->contactRoster($tenant, $table, $playerTeams));
                if ($role === 'trainer') $q->orWhereIn('clubora.members.id', $this->contactRoster($tenant, $table, $trainerTeams));
            }
            $q->orWhereIn('clubora.members.id', $this->contactRoster($tenant, 'team_staff', $childTeams));
            if ($role === 'trainer') $q->orWhereIn('clubora.members.id', $this->contactRoster($tenant, 'team_staff', $this->contactTeams($tenant)));
        })->pluck('clubora.members.id')->all();
        $parentTargets = $role === 'trainer' && $trainerTeams
            ? $this->parentMembers($tenant)->whereIn('clubora.members.id', $this->contactRoster($tenant, 'team_players', $trainerTeams))->pluck('clubora.members.id')->all() : [];
        return $query->whereExists(function ($q) use ($tenant, $role, $targets, $parentTargets) {
            $q->selectRaw('1')->from('clubora.account_member_links as target_link')->where('target_link.tenant_id', $tenant->id)
                ->whereColumn('target_link.account_id', 'candidate.id')->where('target_link.state', 'approved')
                ->where(function ($q) use ($role, $targets, $parentTargets) {
                    $q->where(fn ($q) => $q->where('target_link.kind', 'self')->whereIn('target_link.member_id', $targets));
                    if ($role === 'trainer') $q->orWhere(fn ($q) => $q->where('target_link.kind', 'parent')->whereIn('target_link.member_id', $parentTargets));
                });
        });
    }

    /** SQL equivalent of represented(): approved self, or approved parent of an active minor. */
    private function accountLinks(Tenant $tenant, string $accountColumn)
    {
        return DB::connection('clubunio')->query()->selectRaw('1')->from('clubora.account_member_links as entry_link')
            ->where('entry_link.tenant_id', $tenant->id)->whereColumn('entry_link.account_id', $accountColumn)->where('entry_link.state', 'approved')
            ->whereIn('entry_link.member_id', app(MemberAccessService::class)->activeMembers($tenant)->select('clubora.members.id'))
            ->where(fn ($q) => $q->where('entry_link.kind', 'self')->orWhere(fn ($q) => $q->where('entry_link.kind', 'parent')->whereIn('entry_link.member_id', $this->parentMembers($tenant))));
    }

    private function parentMembers(Tenant $tenant)
    {
        $today = now('Europe/Zurich')->toDateString();
        return app(MemberAccessService::class)->activeMembers($tenant)->where('birth_date', '<=', $today)
            ->whereRaw("birth_date + interval '18 years' > CAST(? AS date)", [$today])->select('clubora.members.id');
    }

    private function contactTeams(Tenant $tenant, $members = null, array $tables = [])
    {
        $q = DB::connection('clubunio')->table('clubora.team_seasons as contact_team')
            ->join('clubora.teams as contact_club_team', fn ($j) => $j->on('contact_club_team.id', '=', 'contact_team.team_id')->on('contact_club_team.tenant_id', '=', 'contact_team.tenant_id'))
            ->where('contact_team.tenant_id', $tenant->id)->where('contact_team.active', true)->where('contact_club_team.active', true);
        app(MobileSeasonScope::class)->teams($q, $tenant, 'contact_team.season_id');
        if ($members !== null) $q->where(function ($q) use ($tenant, $members, $tables) {
            foreach ($tables as $table) $q->orWhereIn('contact_team.id', DB::connection('clubunio')->table('clubora.'.$table)
                ->where('tenant_id', $tenant->id)->where('active', true)->whereIn('member_id', $members)->select('team_season_id'));
        });
        return $q->select('contact_team.id');
    }

    private function contactRoster(Tenant $tenant, string $table, $teams)
    {
        return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('team_season_id', $teams)->select('member_id');
    }
}
