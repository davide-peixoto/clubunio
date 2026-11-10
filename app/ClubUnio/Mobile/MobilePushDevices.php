<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, RateLimiter};
use Illuminate\Support\Str;

final class MobilePushDevices
{
    public function status(Request $request): array
    {
        abort_unless(config('clubunio-mobile-push.enabled', false), 503);
        $data = $request->validate(['installation_id' => 'required|uuid', 'installation_secret' => 'required|regex:/^[0-9a-f]{64}$/D',
            'app_id' => 'required|string|max:180', 'platform' => 'required|in:android,ios']);
        app(MobilePushRegistry::class)->request($request, $data['app_id'], $data['platform']);
        $actor = app(MobileSessions::class)->authenticate($request);
        $this->guardStagePlatform($actor->username, $data['platform']);
        $device = DB::connection('clubunio')->table('clubunio.mobile_push_devices')->where('id', $data['installation_id'])->first();
        if (! $device) return ['revision' => 0, 'bound' => false];
        abort_unless(hash_equals($device->proof_hash, hash('sha256', $data['installation_secret']))
            && $device->app_id === $data['app_id'] && $device->platform === $data['platform'] && $device->origin === $request->getSchemeAndHttpHost(), 403);
        $binding = DB::connection('clubunio')->table('clubunio.mobile_push_bindings')->where('device_id', $device->id)->where('account_id', $actor->id)->first();
        return ['revision' => (int) $device->revision, 'bound' => $binding && $binding->revoked_at === null
            && $binding->session_id === $request->attributes->get('mobile.session')->id];
    }
    public function register(Request $request): array
    {
        $data = $request->validate(['installation_id' => 'required|uuid', 'installation_secret' => 'required|regex:/^[0-9a-f]{64}$/D',
            'app_id' => 'required|string|max:180', 'platform' => 'required|in:android,ios',
            'token' => 'required|string|min:20|max:4096|regex:/^[^\s\x00-\x1f\x7f]+$/D', 'revision' => 'required|integer|min:0']);
        $app = app(MobilePushRegistry::class)->request($request, $data['app_id'], $data['platform']);
        $actor = app(MobileSessions::class)->authenticate($request);
        $this->guardStagePlatform($actor->username, $data['platform']);
        if ($app['fixed_tenant_id'] !== null) {
            $tenant = \App\ClubUnio\Models\Tenant::findOrFail($app['fixed_tenant_id']);
            app(MobileAccess::class)->enter($actor, $tenant);
        }
        $rate = 'mobile-push-register:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rate, 30), 429); RateLimiter::hit($rate, 60);
        try { return DB::connection('clubunio')->transaction(function () use ($request, $data, $app, $actor) {
            // All physical-install updates take the same advisory lock, including the initial insert.
            DB::connection('clubunio')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['mobile-push:'.$data['installation_id']]);
            $session = DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            app(MobileSessions::class)->authenticate($request);
            $devices = DB::connection('clubunio')->table('clubunio.mobile_push_devices');
            $device = (clone $devices)->where('id', $data['installation_id'])->lockForUpdate()->first();
            $hash = hash('sha256', $data['token']);
            $now = now()->toIso8601String();
            if ($device) {
                abort_unless(hash_equals($device->proof_hash, hash('sha256', $data['installation_secret']))
                    && $device->app_id === $data['app_id'] && $device->platform === $data['platform']
                    && $device->origin === $request->getSchemeAndHttpHost()
                    && ($device->fixed_tenant_id === null ? null : (int) $device->fixed_tenant_id) === $app['fixed_tenant_id'], 403);
                abort_unless((int) $device->revision === (int) $data['revision'], 409, 'Push binding changed; refresh before another explicit action.');
                abort_if($device->revoked_at !== null && $device->token_hash === $hash, 409, 'A fresh native registration token is required.');
                abort_if((clone $devices)->where('id', '<>', $device->id)->where('token_hash', $hash)->exists(), 409);
                $revision = (int) $device->revision + (($device->token_hash !== $hash || $device->revoked_at !== null) ? 1 : 0);
                if ($revision !== (int) $device->revision) (clone $devices)->where('id', $device->id)->update([
                    'token_cipher' => Crypt::encryptString($data['token']), 'token_hash' => $hash,
                    'revision' => $revision, 'revoked_at' => null, 'updated_at' => $now]);
            } else {
                abort_unless((int) $data['revision'] === 0, 409);
                // A token cannot be stolen from another install by presenting another UUID.
                abort_if((clone $devices)->where('token_hash', $hash)->exists(), 409);
                $revision = 1;
                $devices->insert(['id' => $data['installation_id'], 'proof_hash' => hash('sha256', $data['installation_secret']),
                    'app_id' => $data['app_id'], 'platform' => $data['platform'], 'origin' => $request->getSchemeAndHttpHost(),
                    'fixed_tenant_id' => $app['fixed_tenant_id'], 'token_cipher' => Crypt::encryptString($data['token']),
                    'token_hash' => $hash, 'revision' => $revision, 'created_at' => $now, 'updated_at' => $now]);
            }
            $bindings = DB::connection('clubunio')->table('clubunio.mobile_push_bindings');
            $binding = (clone $bindings)->where('device_id', $data['installation_id'])->where('account_id', $actor->id)->lockForUpdate()->first();
            if ($binding) {
                $bindingRevision = (int) $binding->revision + (($binding->session_id !== $session->id || $binding->revoked_at !== null) ? 1 : 0);
                (clone $bindings)->where('id', $binding->id)->update(['session_id' => $session->id, 'revision' => $bindingRevision, 'revoked_at' => null, 'updated_at' => $now]);
            } else {
                $bindingRevision = 1;
                $bindings->insert(['id' => (string) Str::uuid(), 'device_id' => $data['installation_id'], 'account_id' => $actor->id,
                    'session_id' => $session->id, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            return ['installation_id' => $data['installation_id'], 'revision' => $revision, 'binding_revision' => $bindingRevision];
        }); } catch (\Illuminate\Database\QueryException $error) {
            if ($error->getCode() === '23505') abort(409, 'Push token already bound.');
            throw $error;
        }
    }

    private function guardStagePlatform(string $username, string $platform): void
    {
        if (config('app.url') === 'https://stage.clubunio.com') {
            abort_unless(StageMobilePilot::pushPlatform($username) === $platform, 403);
        }
    }

    public function revoke(Request $request): array
    {
        abort_unless(config('clubunio-mobile-push.enabled', false), 503);
        $data = $request->validate(['installation_id' => 'required|uuid', 'installation_secret' => 'required|regex:/^[0-9a-f]{64}$/D']);
        $actor = app(MobileSessions::class)->authenticate($request);
        return DB::connection('clubunio')->transaction(function () use ($data, $actor) {
            $device = DB::connection('clubunio')->table('clubunio.mobile_push_devices')->where('id', $data['installation_id'])->lockForUpdate()->first();
            abort_unless($device && hash_equals($device->proof_hash, hash('sha256', $data['installation_secret'])), 403);
            // Current account only. Other remembered accounts on the install retain their own binding.
            DB::connection('clubunio')->table('clubunio.mobile_push_bindings')->where('device_id', $device->id)->where('account_id', $actor->id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()->toIso8601String(), 'revision' => DB::connection('clubunio')->raw('revision+1')]);
            return ['revoked' => true];
        });
    }
}
