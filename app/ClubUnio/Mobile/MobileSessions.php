<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class MobileSessions
{
    public function login(Request $request): array
    {
        app(MobileBootstrapState::class)->authorizeLogin($request);
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:4096', 'device_name' => 'required|string|max:100']);
        $username = mb_strtolower(trim($data['username']));
        $keys = ['mobile-login:'.hash('sha256', $request->ip().'|'.$username) => 5,
            'mobile-login-ip:'.hash('sha256', $request->ip()) => 30];
        foreach ($keys as $key => $limit) {
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
            RateLimiter::hit($key, 60);
        }
        $provider = Auth::createUserProvider('clubunio_users');
        $credentials = ['username' => $username, 'password' => $data['password'], 'active' => true, 'api_only' => false];
        $actor = $provider->retrieveByCredentials($credentials);
        // Existing hashes/provider; no password copying, rehash or second account store.
        abort_unless($actor instanceof Account && $provider->validateCredentials($actor, $credentials), 401);
        $bound = app(TenantDomainService::class)->resolve($request);
        $tenants = $actor->tenants()->get()->filter(fn ($tenant) => (! $bound || $bound->is($tenant))
            && app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($actor, $tenant));
        if ($tenants->isEmpty() && config('clubunio-mobile.public_identity.enabled', false)
            && app(MobileIdentityChallenges::class)->accessPending($actor, $bound)) {
            throw new MobileAccessPending;
        }
        abort_if($tenants->isEmpty(), 401);
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $expiry = now()->addDays((int) config('clubunio-mobile.session_days', 30));
        DB::connection('clubunio')->transaction(function () use ($actor, $provider, $credentials, $id, $secret, $data, $expiry) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && ! $fresh->api_only && $provider->validateCredentials($fresh, $credentials), 401);
            DB::connection('clubunio')->table('clubora.mobile_sessions')->insert(['id' => $id, 'account_id' => $actor->id,
                'token_hash' => hash('sha256', $secret), 'device_name' => $data['device_name'], 'expires_at' => $expiry->toIso8601String(), 'created_at' => now()->toIso8601String()]);
        });
        RateLimiter::clear(array_key_first($keys));

        return ['token' => $id.'.'.$secret, 'expires_at' => $expiry->toIso8601String(),
            'account' => ['id' => (int) $actor->id, 'name' => $actor->display_name],
            'tenants' => $tenants->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->values()->all()];
    }

    public function authenticate(Request $request): Account
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})$/D', $token, $parts), 401);
        $session = DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $parts[1])->whereNull('revoked_at')
            ->where('expires_at', '>', now()->toIso8601String())->first();
        abort_unless($session && hash_equals($session->token_hash, hash('sha256', $parts[2])), 401);
        $actor = Account::whereKey($session->account_id)->where('active', true)->where('api_only', false)->first();
        abort_unless($actor, 401);
        $request->attributes->set('mobile.session', $session);

        return $actor;
    }
}
