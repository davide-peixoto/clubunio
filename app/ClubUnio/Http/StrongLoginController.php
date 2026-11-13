<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Mobile\{MobileBootstrapState, MobileSessions};
use App\ClubUnio\Services\{AccountSecurity, LanguagePreferences, MemberAccessService, PlatformAdministrationService, TenantDomainService};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Crypt, RateLimiter};
use Illuminate\Support\Str;
use lbuchs\WebAuthn\WebAuthnException;

final class StrongLoginController
{
    public function __construct(private AccountSecurity $security) {}

    public function show(Request $request)
    {
        $this->security->origin($request);
        $mode = $request->query('mode', 'clubunio');
        abort_unless(in_array($mode, ['clubunio', 'portal', 'mobile', 'platform'], true), 404);
        $pair = $mode === 'mobile' ? $request->validate(['pair' => 'required|uuid'])['pair'] : null;
        $locale = $request->query('locale');
        if ($mode === 'mobile' && is_string($locale)) {
            app()->setLocale(app(LanguagePreferences::class)->resolve($locale, app()->getLocale()));
        }
        $showSetup = $request->query('setup') === '1' || $mode === 'mobile';
        return response()->view('clubunio.security.login', compact('mode', 'pair', 'showSetup'))->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function action(Request $request, string $operation)
    {
        abort_unless($request->isJson(), 415);
        $origin = $this->security->origin($request);
        $mode = $request->validate(['mode' => 'required|in:clubunio,portal,mobile,platform'])['mode'];
        if ($mode === 'mobile') {
            $this->validPair($request);
        }
        abort_if($request->attributes->has('clubunio_impersonation'), 403);
        $web = $this->security->webAuthn($origin);
        $result = match ($operation) {
            'passkey-options' => $this->options($request, $web, $mode, false),
            'passkey' => $this->finish($request, $mode, ...$this->security->passkeyLogin($origin,
                $this->challenge($request, $mode, false), $request->validate([
                    'id' => 'required|string|max:2048', 'client' => 'required|string|max:8192',
                    'authenticator' => 'required|string|max:8192', 'signature' => 'required|string|max:2048',
                ]))),
            'password' => $this->password($request, $mode),
            'setup-start' => $this->selfEnrollment($request, $mode),
            'setup-totp' => $this->startTotp($request, $mode),
            'setup-totp-confirm' => $this->confirmTotp($request, $mode),
            'setup-passkey' => $this->options($request, $web, $mode, true),
            'setup-passkey-confirm' => $this->confirmPasskey($request, $mode, $origin, $web),
            default => abort(404),
        };
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function credentials(Request $request, bool $enrollment = false): array
    {
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:4096']
            + ($enrollment ? ['invitation' => 'required|string|size:40'] : ['code' => 'nullable|string|max:40']));
        $key = 'strong-login:'.hash('sha256', mb_strtolower(trim($data['username'])));
        abort_if(RateLimiter::tooManyAttempts($key, 12), 429);
        RateLimiter::hit($key, 300);
        $actor = $this->security->password($data['username'], $data['password']);
        if ($enrollment) $this->security->checkInvitation($actor, $data['invitation']);
        return [$actor, $data];
    }

    private function password(Request $request, string $mode): array
    {
        [$actor, $data] = $this->credentials($request);
        $code = $data['code'] ?? null;
        if ($code && preg_match('/^[a-f0-9]{32}$/D', $code)) {
            return ['setup_code' => $this->security->recover($actor, $code)];
        }
        return $this->finish($request, $mode, $actor, $this->security->verifySecondFactor($actor, $code));
    }

    private function selfEnrollment(Request $request, string $mode): array
    {
        [$actor] = $this->credentials($request);
        if ($this->security->mustEnroll($actor)) {
            return ['setup_code' => $this->security->invitation($actor)];
        }
        abort_if($mode === 'mobile', 403);
        $guard = $mode === 'platform' ? 'clubunio' : $mode;
        abort_unless(Auth::guard($guard)->id() === $actor->id, 403);
        $stamp = $request->session()->get('clubunio.security.'.$guard);
        return ['setup_code' => $this->security->invitation($actor, existingStamp: is_string($stamp) ? $stamp : null)];
    }

    private function options(Request $request, $web, string $mode, bool $setup): array
    {
        if ($setup) {
            [$actor] = $this->credentials($request, true);
            $options = $web->getCreateArgs(hash('sha256', 'clubunio:'.$actor->id, true), $actor->username, $actor->display_name, 90, true, 'required');
        } else {
            $options = $web->getGetArgs([], 90, true, true, true, true, true, 'required');
        }
        $request->session()->put('clubunio.security.passkey', [
            'mode' => $mode, 'setup' => $setup, 'at' => time(), 'actor' => isset($actor) ? (int)$actor->id : null,
            'pair' => $mode === 'mobile' ? $request->input('pair') : null,
            'challenge' => base64_encode($web->getChallenge()->getBinaryString()),
        ]);
        return (array) $options->publicKey;
    }

    private function challenge(Request $request, string $mode, bool $setup, ?int $actor = null): string
    {
        $state = $request->session()->pull('clubunio.security.passkey');
        abort_unless($state && $state['mode'] === $mode && $state['setup'] === $setup && $state['actor'] === $actor && $state['at'] > time() - 120, 422);
        abort_unless($mode !== 'mobile' || $state['pair'] === $request->input('pair'), 422);
        return base64_decode($state['challenge'], true);
    }

    private function startTotp(Request $request, string $mode): array
    {
        [$actor] = $this->credentials($request, true);
        $totp = $this->security->totp();
        $secret = $totp->generateSecretKey();
        $request->session()->put('clubunio.security.totp', ['actor' => (int)$actor->id, 'at' => time(), 'mode' => $mode,
            'pair' => $mode === 'mobile' ? $request->input('pair') : null, 'secret' => Crypt::encryptString($secret)]);
        return ['secret' => $secret];
    }

    private function confirmTotp(Request $request, string $mode): array
    {
        [$actor, $data] = $this->credentials($request, true);
        $code = $request->validate(['code' => 'required|string|regex:/^\d{6}$/D'])['code'];
        $state = $request->session()->get('clubunio.security.totp');
        abort_unless($state && $state['actor'] === (int)$actor->id && $state['mode'] === $mode && $state['at'] > time() - 300, 422);
        abort_unless($mode !== 'mobile' || $state['pair'] === $request->input('pair'), 422);
        $step = $this->security->totp()->verifyKeyNewer(Crypt::decryptString($state['secret']), $code, 0, 1);
        abort_unless(is_int($step), 422);
        $codes = $this->security->enroll($actor, $data['invitation'], ['totp_encrypted' => $state['secret'], 'totp_last_step' => $step]);
        $request->session()->forget('clubunio.security.totp');
        return ['codes' => $codes];
    }

    private function confirmPasskey(Request $request, string $mode, string $origin, $web): array
    {
        [$actor, $data] = $this->credentials($request, true);
        $input = $request->validate(['client' => 'required|string|max:8192', 'attestation' => 'required|string|max:32768']);
        $challenge = $this->challenge($request, $mode, true, (int)$actor->id);
        try {
            $key = $web->processCreate($this->security->clientData($input['client'], $origin),
                $this->security->base64($input['attestation']), $challenge, true);
        } catch (WebAuthnException) { abort(422); }
        return ['codes' => $this->security->enroll($actor, $data['invitation'], [], [
            'credential_hash' => hash('sha256', $key->credentialId), 'public_key' => $key->credentialPublicKey,
            'rp_id' => parse_url($origin, PHP_URL_HOST), 'signature_counter' => (int)$key->signatureCounter,
        ])];
    }

    private function pairs() { return $this->security->db()->table('clubora.account_security_pairs'); }

    public function pair(Request $request, string $operation)
    {
        abort_unless($request->isJson() && $request->query->count() === 0, 415);
        $this->security->origin($request);
        app(MobileBootstrapState::class)->authorizeLogin($request);
        if ($operation === 'begin') {
            $data = $request->validate(['proof_hash' => 'required|regex:/^[a-f0-9]{64}$/D']);
            $id = (string) Str::uuid();
            $confirmation = (string) random_int(10000000, 99999999);
            $this->pairs()->where('expires_at', '<', now())->delete();
            $this->pairs()->insert(['id' => $id, 'proof_hash' => $data['proof_hash'],
                'confirmation_hash' => $this->pairHash($id, $confirmation), 'expires_at' => now()->addMinutes(5)]);
            return response()->json(['data' => ['pair' => $id, 'confirmation' => $confirmation]])->header('Cache-Control', 'private, no-store');
        }
        abort_unless($operation === 'redeem', 404);
        $data = $request->validate(['pair' => 'required|uuid', 'proof' => 'required|regex:/^[a-f0-9]{64}$/D']);
        $result = $this->security->db()->transaction(function () use ($request, $data) {
            $query = $this->pairs()->where('id', $data['pair'])->where('expires_at', '>', now())->whereNull('consumed_at');
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row && hash_equals($row->proof_hash, hash('sha256', $data['proof'])), 404);
            if (!$row->account_id) return ['data' => ['pending' => true]];
            $actor = Account::whereKey($row->account_id)->where('active', true)->where('api_only', false)->firstOrFail();
            $result = app(MobileSessions::class)->issue($request, $actor, 'ClubUnio App', strongStamp: $row->session_stamp);
            $query->update(['consumed_at' => now()]);
            return ['data' => $result];
        });
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function pairHash(string $id, string $confirmation): string
    {
        return hash_hmac('sha256', $id.'|'.$confirmation, (string) config('app.key'));
    }

    private function validPair(Request $request): void
    {
        $data = $request->validate(['pair' => 'required|uuid', 'confirmation' => 'required|regex:/^\d{8}$/D']);
        $this->security->rate('pair', $data['pair'], 12, 300);
        abort_unless($this->pairs()->where('id', $data['pair'])->where('confirmation_hash', $this->pairHash($data['pair'], $data['confirmation']))
            ->where('expires_at', '>', now())->whereNull('account_id')->whereNull('consumed_at')->exists(), 422);
    }

    private function finish(Request $request, string $mode, Account $actor, string $stamp): array
    {
        if ($mode === 'mobile') {
            $data = $request->validate(['pair' => 'required|uuid', 'confirmation' => 'required|regex:/^\d{8}$/D']);
            $changed = $this->pairs()->where('id', $data['pair'])->where('confirmation_hash', $this->pairHash($data['pair'], $data['confirmation']))
                ->where('expires_at', '>', now())->whereNull('account_id')->whereNull('consumed_at')
                ->update(['account_id' => $actor->id, 'session_stamp' => $stamp]);
            abort_unless($changed === 1, 422);
            return ['paired' => true];
        }
        $guard = $mode === 'platform' ? 'clubunio' : $mode;
        if ($mode === 'platform') {
            abort_if(app(TenantDomainService::class)->resolve($request), 404);
            abort_unless(app(PlatformAdministrationService::class)->allowed($actor), 403);
            $redirect = route('clubunio.platform.index');
        } elseif ($mode === 'clubunio') {
            abort_unless($actor->canAccessPanel(Filament::getPanel('clubunio')), 403);
            $redirect = url('/');
        } else {
            $tenant = $actor->tenants()->get()->first(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant));
            abort_unless($tenant, 403);
            $redirect = route('clubunio.portal.members', $tenant->slug);
        }
        abort_if($request->attributes->has('clubunio_impersonation'), 403);
        Auth::guard($guard)->login($actor);
        $request->session()->regenerate();
        $request->session()->put('clubunio.security.'.$guard, $stamp);
        $request->session()->put('clubunio.security.verified_at.'.$guard, time());
        return ['redirect' => $redirect];
    }
}
