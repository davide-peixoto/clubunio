<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, MemberAccessService, ModulePermissionService, PermissionCatalog};
use Illuminate\Support\Facades\DB;

/** Communication-only grants. Never substitute this policy for MobileAccess in finance. */
final class ChatAccess
{
    /** The session adapter calls this only after verifying its actor/tenant context. */
    public function duringRead(Account $actor, Tenant $tenant, callable $read): mixed
    {
        $request = request();
        if (!$request->isMethod('GET')) return $read();
        $previous = $request->attributes->get(self::class);
        $request->attributes->set(self::class, ['request' => spl_object_id($request),
            'account' => (int) $actor->id, 'tenant' => (int) $tenant->id, 'scope' => new \stdClass,
            'generation' => $request->attributes->get(self::class.'.generation', 0), 'values' => []]);
        try {
            $this->enter($actor, $tenant);
            return $read();
        } finally {
            if ($previous === null || $previous['generation'] !== $request->attributes->get(self::class.'.generation', 0)) $request->attributes->remove(self::class);
            else $request->attributes->set(self::class, $previous);
        }
    }

    private function remember(Account $actor, Tenant $tenant, string $key, \Closure $read): mixed
    {
        $request = request();
        $frame = $request->attributes->get(self::class);
        if (!$request->isMethod('GET') || !$frame || $frame['request'] !== spl_object_id($request)
            || $frame['account'] !== (int) $actor->id || $frame['tenant'] !== (int) $tenant->id
            || $frame['generation'] !== $request->attributes->get(self::class.'.generation', 0)) {
            return app(MobileReadContext::class)->remember($actor, 'chat:'.$tenant->id.':'.$key, $read);
        }
        if (array_key_exists($key, $frame['values'])) return $frame['values'][$key];
        $value = $read();
        $current = $request->attributes->get(self::class);
        if ($current && $current['scope'] === $frame['scope']) {
            $current['values'][$key] = $value;
            $request->attributes->set(self::class, $current);
        }
        return $value;
    }

    public function isProfile(Account $actor, Tenant $tenant): bool
    {
        return $this->remember($actor, $tenant, 'profile', fn () => app(ModulePermissionService::class)->isProfile($actor, $tenant));
    }

    public function enter(Account $actor, Tenant $tenant): void
    {
        $simulation = request()->attributes->get('clubunio_impersonation');
        abort_if($simulation && ((int) $simulation->tenant_id !== (int) $tenant->id
            || (int) $simulation->target_id !== (int) $actor->id), 403);
        $this->remember($actor, $tenant, 'enter', function () use ($actor, $tenant) {
            $fresh = app(MobileReadContext::class)->freshActor($actor);
            abort_unless($fresh?->active && !$fresh->api_only, 403);
            if ($this->isProfile($fresh, $tenant)) {
                app(ModulePermissionService::class)->authorize($fresh, $tenant, 'chat', 'view');
            } else {
                app(MobileAccess::class)->enter($fresh, $tenant);
            }
            return true;
        });
    }

    public function allows(Account $actor, Tenant $tenant, string $function): bool
    {
        if (!array_key_exists($function, PermissionCatalog::functions('chat'))) return false;
        $simulation = request()->attributes->get('clubunio_impersonation');
        if ($simulation && ($function !== 'view' || (int) $simulation->tenant_id !== (int) $tenant->id
            || (int) $simulation->target_id !== (int) $actor->id)) return false;
        return $this->remember($actor, $tenant, 'allows:'.$function, function () use ($actor, $tenant, $function) {
            try { $this->enter($actor, $tenant); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { return false; }
            $permissions = app(ModulePermissionService::class);
            if ($this->isProfile($actor, $tenant)) return $permissions->allows($actor, $tenant, 'chat', $function);
            if (in_array($function, ['announce', 'groups'], true)) {
                $role = $this->remember($actor, $tenant, 'role', fn () => app(MemberAccessService::class)->membership($actor, $tenant)?->role);
                return in_array($role, ['admin', 'office'], true);
            }
            return in_array($function, ['view', 'send', 'upload', 'download', 'poll', 'moderate'], true);
        });
    }

    public function authorize(Account $actor, Tenant $tenant, string $function): void
    {
        if (!in_array($function, ['view', 'download'], true)) {
            $this->authorizeWrite($actor, $tenant, $function);
            return;
        }
        abort_unless($this->allows($actor, $tenant, $function), 403);
    }

    public function authorizeWrite(Account $actor, Tenant $tenant, string $function = 'send'): void
    {
        abort_if(request()->attributes->has('clubunio_impersonation'), 403);
        // A mutation ends any read snapshot, including when invoked directly during a GET.
        // Subsequent ownership/group checks must not reuse pre-mutation entitlements.
        request()->attributes->set(self::class.'.generation', request()->attributes->get(self::class.'.generation', 0) + 1);
        foreach ([self::class, MobileReadContext::class, BackofficeReadContext::class] as $context) {
            request()->attributes->remove($context);
        }
        abort_unless($this->allows($actor, $tenant, $function), 403);
    }

    public function admin(Account $actor, Tenant $tenant): bool
    {
        return $this->allows($actor, $tenant, 'groups');
    }

    public function teams(Account $actor, Tenant $tenant, bool $staffOnly = false)
    {
        $this->enter($actor, $tenant);
        if (!$this->admin($actor, $tenant) && !$this->isProfile($actor, $tenant)) {
            return app(MobileAccess::class)->teams($actor, $tenant, $staffOnly);
        }
        $q = DB::connection('clubunio')->table('clubora.team_seasons as ts')
            ->join('clubora.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))
            ->join('clubora.seasons as s', fn ($j) => $j->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
            ->where('ts.tenant_id', $tenant->id)->where('ts.active', true)->where('t.active', true);
        app(MobileSeasonScope::class)->teams($q, $tenant, 'ts.season_id');
        if (!$this->admin($actor, $tenant)) $q->whereRaw('1=0');
        return $q->select(['ts.id', 'ts.team_id', 'ts.season_id', 'ts.label', 't.name', 's.name as season']);
    }

    public function contacts(Account $actor, Tenant $tenant, string $search = '', int $limit = 40): array
    {
        $this->enter($actor, $tenant);
        if (!$this->isProfile($actor, $tenant)) {
            return app(MobileAccess::class)->contacts($actor, $tenant, $search, $limit);
        }
        $q = $this->profileContacts($actor, $tenant);
        if (trim($search) !== '') $q->whereRaw("lower(a.display_name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%']);
        return $q->orderBy('a.display_name')->orderBy('a.id')->limit(max(1, min(40, $limit)))
            ->get(['a.id', 'a.display_name as name'])->map(fn ($r) => ['id' => (int)$r->id, 'name' => $r->name])->all();
    }

    private function profileContacts(Account $actor, Tenant $tenant)
    {
        // Profiles can initiate contact with the office, never discover arbitrary private pairs.
        return DB::connection('clubunio')->table('clubora.accounts as a')
            ->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->where('ta.tenant_id', $tenant->id)->whereIn('ta.role', ['admin', 'office'])
            ->where('a.active', true)->where('a.api_only', false)->where('a.id', '<>', $actor->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.chat_blocks as b')->where('b.tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('b.account_id', $actor->id)->whereColumn('b.other_account_id', 'a.id'))
                    ->orWhere(fn ($q) => $q->whereColumn('b.account_id', 'a.id')->where('b.other_account_id', $actor->id))));
    }

    public function canContact(Account $actor, Account $other, Tenant $tenant): bool
    {
        $this->enter($actor, $tenant);
        $profile = $this->isProfile($actor, $tenant);
        $otherProfile = $this->isProfile($other, $tenant);
        if (!$profile && !$otherProfile) return app(MobileAccess::class)->canContact($actor, $other, $tenant);
        if ($profile && $otherProfile) return false;
        $p = $profile ? $actor : $other; $office = $profile ? $other : $actor;
        try { $this->enter($p, $tenant); $this->enter($office, $tenant); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { return false; }
        return $this->profileContacts($p, $tenant)->where('a.id', $office->id)->exists();
    }

    public function event(Account $actor, Tenant $tenant, int $id): object
    {
        $this->enter($actor, $tenant);
        if (!$this->admin($actor, $tenant) && !$this->isProfile($actor, $tenant)) {
            return app(\App\ClubUnio\Services\TeamPlanningService::class)->event($actor, $tenant, $id);
        }
        $row = app(\App\ClubUnio\Services\TeamPlanningService::class)->table($tenant, 'team_events')->where('id', $id)->first();
        abort_unless($row && $this->teams($actor, $tenant)->where('ts.id', $row->team_season_id)->exists(), 404);
        return $row;
    }
}
