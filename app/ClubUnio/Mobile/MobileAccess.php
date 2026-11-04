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

    public function contacts(Account $actor, Tenant $tenant): array
    {
        $this->enter($actor, $tenant);
        $result = [];
        foreach ($tenant->accounts()->where('active', true)->where('api_only', false)->orderBy('clubora.accounts.id')->lazy(100) as $candidate) {
            if ((int) $candidate->id === (int) $actor->id) {
                continue;
            }
            if (! app(MemberAccessService::class)->canEnter($candidate, $tenant)) {
                continue;
            }
            if ($this->canContact($actor, $candidate, $tenant)) {
                $result[] = ['id' => (int) $candidate->id, 'name' => $candidate->display_name];
            }
            if (count($result) >= 100) {
                break;
            }
        }

        return $result;
    }

    public function canContact(Account $actor, Account $other, Tenant $tenant): bool
    {
        $this->enter($actor, $tenant);
        if ((int) $actor->id === (int) $other->id || ! $other->active || $other->api_only
            || ! app(MemberAccessService::class)->canEnter($other, $tenant)) {
            return false;
        }
        if (DB::connection('clubunio')->table('clubora.chat_blocks')->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('account_id', $actor->id)->where('other_account_id', $other->id))
                ->orWhere(fn ($a) => $a->where('account_id', $other->id)->where('other_account_id', $actor->id)))->exists()) { return false; }
        if ($this->admin($actor, $tenant) || $this->admin($other, $tenant)) {
            return true;
        }
        $access = app(MemberAccessService::class);
        $teams = $this->teams($actor, $tenant)->pluck('ts.id')->all();
        $otherTeams = $this->teams($other, $tenant)->pluck('ts.id')->all();
        $shared = array_intersect($teams, $otherTeams);
        if (! $shared) { return false; }
        $minor = fn ($person) => $access->represented($person, $tenant, true)->where('birth_date', '>', now('Europe/Zurich')->subYears(18)->toDateString())->exists();
        if ($minor($actor) || $minor($other)) {
            $staff = array_merge($access->responsibleTeams($actor, $tenant), $access->responsibleTeams($other, $tenant));
            $related = $access->represented($actor, $tenant)->whereIn('clubora.members.id', $access->represented($other, $tenant)->select('clubora.members.id'))->exists();
            return (bool) array_intersect($shared, $staff) || $related;
        }
        return true;
    }
}
