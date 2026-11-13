<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Crypt, DB, RateLimiter};
use Illuminate\Support\Timebox;
use lbuchs\WebAuthn\{WebAuthn, WebAuthnException};
use PragmaRX\Google2FA\Google2FA;

final class AccountSecurity
{
    public function db() { return DB::connection('clubunio'); }
    public function settings() { return $this->db()->table('clubora.account_security'); }
    private function keys() { return $this->db()->table('clubora.account_passkeys'); }

    public function enabled(): bool { return config('clubunio-security.enabled') === true; }

    public function origin(Request $request): string
    {
        $origin = config('clubunio-security.origin');
        abort_unless($this->enabled() && $request->isSecure() && is_string($origin)
            && $origin === $request->getSchemeAndHttpHost(), 404);

        return $origin;
    }

    public function password(string $username, #[\SensitiveParameter] string $password): Account
    {
        return app(Timebox::class)->call(function (Timebox $timebox) use ($username, $password) {
            $provider = Auth::createUserProvider('clubunio_users');
            $credentials = ['username' => mb_strtolower(trim($username)), 'password' => $password, 'active' => true, 'api_only' => false];
            $actor = $provider->retrieveByCredentials($credentials);
            abort_unless($actor instanceof Account && $provider->validateCredentials($actor, $credentials), 401);
            $timebox->returnEarly();
            return $actor;
        }, 200_000);
    }

    public function configured(Account $actor): bool
    {
        return $this->enabled() && ($this->settings()->where('account_id', $actor->id)->whereNotNull('totp_encrypted')->exists()
            || $this->keys()->where('account_id', $actor->id)->exists());
    }

    public function stamp(Account $actor): string
    {
        $row = $this->settings()->where('account_id', $actor->id)->first();
        return $this->hash($actor->id.'|'.$actor->password.'|'.($row->session_version ?? ''));
    }

    public function rate(string $scope, string $subject, int $limit, int $seconds): void
    {
        $key = 'security:'.$scope.':'.$this->hash($subject);
        abort_unless(RateLimiter::attempt($key, $limit, fn () => true, $seconds), 429);
    }

    public function verifySecondFactor(Account $actor, #[\SensitiveParameter] ?string $code): string
    {
        abort_unless($code && preg_match('/^\d{6}$/D', $code), 401);
        $this->rate('factor', (string)$actor->id, 8, 300);
        return $this->db()->transaction(function () use ($actor, $code) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && !$fresh->api_only && hash_equals($fresh->password, $actor->password), 401);
            $row = $this->settings()->where('account_id', $actor->id)->lockForUpdate()->first();
            abort_unless($row?->totp_encrypted, 401);
            $step = $this->totp()->verifyKeyNewer(Crypt::decryptString($row->totp_encrypted), $code, (int) $row->totp_last_step, 1);
            abort_unless(is_int($step), 401);
            $this->settings()->where('account_id', $actor->id)->update(['totp_last_step' => $step]);
            return $this->stamp($fresh);
        });
    }

    public function recover(Account $actor, #[\SensitiveParameter] string $code): string
    {
        $this->rate('factor', (string)$actor->id, 8, 300);
        return $this->db()->transaction(function () use ($actor, $code) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && !$fresh->api_only && hash_equals($fresh->password, $actor->password), 401);
            $row = $this->settings()->where('account_id', $actor->id)->lockForUpdate()->first();
            abort_unless($row && $this->configured($fresh), 401);
            $hash = $this->hash($code);
            $hashes = json_decode($row->recovery_hashes, true, flags: JSON_THROW_ON_ERROR);
            $index = array_search($hash, $hashes, true);
            abort_if($index === false, 401);
            unset($hashes[$index]);
            $setupCode = bin2hex(random_bytes(20));
            $version = bin2hex(random_bytes(16));
            $this->settings()->where('account_id', $actor->id)->update(['recovery_hashes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR),
                'session_version' => $version, 'invitation_recovery' => true,
                'invitation_email' => false, 'invitation_add_method' => false,
                'invitation_hash' => $this->invitationHash($fresh, $setupCode, $version), 'invitation_expires_at' => now()->addMinutes(10)]);
            $this->db()->table('clubora.mobile_sessions')->where('account_id', $actor->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            return $setupCode;
        });
    }

    public function invitation(Account $actor, bool $recovery = false, bool $email = false, ?string $existingStamp = null): string
    {
        return $this->db()->transaction(function () use ($actor, $recovery, $email, $existingStamp) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $configured = $this->configured($fresh);
            $addMethod = $configured && !$recovery && $existingStamp && hash_equals($this->stamp($fresh), $existingStamp);
            abort_if(!$fresh->active || $fresh->api_only || !hash_equals($fresh->password, $actor->password)
                || ($configured && !$recovery && !$addMethod), 409);
            abort_if($email && (!$recovery || !$this->emailRecoveryAllowed($fresh)), 403);
            $code = bin2hex(random_bytes(20));
            $version = $this->settings()->where('account_id', $fresh->id)->value('session_version') ?? '';
            $this->settings()->upsert([['account_id' => $actor->id, 'invitation_hash' => $this->invitationHash($fresh, $code, $version),
                'invitation_expires_at' => now()->addMinutes(30)->toIso8601String(),
                'invitation_recovery' => $recovery, 'invitation_email' => $email, 'invitation_add_method' => $addMethod]], ['account_id'],
                ['invitation_hash', 'invitation_expires_at', 'invitation_recovery', 'invitation_email', 'invitation_add_method']);
            return $code;
        });
    }

    public function emailRecoveryAllowed(Account $actor): bool
    {
        // Account-wide decision, not just the club that issued the mail.
        $roles = $this->db()->table('clubora.tenant_accounts')->where('account_id', $actor->id)->pluck('role');
        return $roles->isNotEmpty() && $roles->every(fn ($role) => $role === 'member')
            && !$this->db()->table('clubora.platform_admins')->where('account_id', $actor->id)->where('active', true)->exists();
    }

    public function checkInvitation(Account $actor, #[\SensitiveParameter] string $code): void
    {
        $row = $this->settings()->where('account_id', $actor->id)->first();
        abort_unless($row?->invitation_hash && $row->invitation_expires_at
            && now()->lt(\Illuminate\Support\Carbon::parse($row->invitation_expires_at))
            && hash_equals($row->invitation_hash, $this->invitationHash($actor, $code, $row->session_version ?? ''))
            && (!$row->invitation_email || $this->emailRecoveryAllowed($actor)), 403);
    }

    public function enroll(Account $actor, string $invitation, array $updates, ?array $passkey = null): array
    {
        return $this->db()->transaction(function () use ($actor, $invitation, $updates, $passkey) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && ! $fresh->api_only && hash_equals($fresh->password, $actor->password), 403);
            $row = $this->settings()->where('account_id', $actor->id)->lockForUpdate()->firstOrFail();
            $this->checkInvitation($fresh, $invitation);
            abort_if($this->configured($fresh) && !$row->invitation_recovery && !$row->invitation_add_method, 409);
            if ($row->invitation_add_method) {
                abort_if(!$passkey && $row->totp_encrypted, 409);
                abort_if($passkey && ($this->keys()->where('credential_hash', $passkey['credential_hash'])->exists()
                    || $this->keys()->where('account_id', $actor->id)->count() >= 5), 409);
            }
            if ($row->invitation_recovery) {
                $this->keys()->where('account_id', $actor->id)->delete();
                $updates += ['totp_encrypted' => null, 'totp_last_step' => null];
            }
            $codes = array_map(fn () => bin2hex(random_bytes(16)), range(1, 8));
            if ($passkey) $this->keys()->insert($passkey + ['account_id' => $actor->id]);
            $this->settings()->where('account_id', $actor->id)->update($updates + [
                'invitation_hash' => null, 'invitation_expires_at' => null, 'invitation_recovery' => false,
                'invitation_email' => false, 'invitation_add_method' => false,
                'session_version' => bin2hex(random_bytes(16)),
                'recovery_hashes' => json_encode(array_map($this->hash(...), $codes), JSON_THROW_ON_ERROR),
            ]);
            $this->db()->table('clubora.mobile_sessions')->where('account_id', $actor->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            return $codes;
        });
    }

    public function totp(): Google2FA { return new Google2FA; }
    public function webAuthn(string $origin): WebAuthn { return new WebAuthn('ClubUnio', parse_url($origin, PHP_URL_HOST), ['none'], true); }
    private function hash(#[\SensitiveParameter] string $value): string { return hash_hmac('sha256', $value, (string) config('app.key')); }

    private function invitationHash(Account $actor, #[\SensitiveParameter] string $code, string $version): string
    {
        return $this->hash('setup|'.$actor->id.'|'.$actor->password.'|'.$version.'|'.$code);
    }

    public function base64(string $value): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]+$/D', $value), 422);
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        abort_unless(is_string($bytes) && $bytes !== '', 422);
        return $bytes;
    }

    public function clientData(string $value, string $origin): string
    {
        $bytes = $this->base64($value);
        $data = json_decode($bytes, true);
        abort_unless(is_array($data) && ($data['origin'] ?? null) === $origin && ($data['crossOrigin'] ?? false) === false
            && !array_key_exists('topOrigin', $data), 422);
        return $bytes;
    }

    public function passkeyLogin(string $origin, string $challenge, array $input): array
    {
        return $this->db()->transaction(function () use ($origin, $challenge, $input) {
            $query = $this->keys()->where('credential_hash', hash('sha256', $this->base64($input['id'])))
                ->where('rp_id', parse_url($origin, PHP_URL_HOST));
            $owner = (clone $query)->value('account_id');
            abort_unless($owner, 401);
            $actor = Account::whereKey($owner)->where('active', true)->where('api_only', false)->lockForUpdate()->first();
            abort_unless($actor, 401);
            $key = (clone $query)->where('account_id', $owner)->lockForUpdate()->first();
            abort_unless($key, 401);
            $verifier = $this->webAuthn($origin);
            try {
                $verifier->processGet($this->clientData($input['client'], $origin), $this->base64($input['authenticator']),
                    $this->base64($input['signature']), $key->public_key, $challenge, (int) $key->signature_counter, true);
            } catch (WebAuthnException) { abort(401); }
            $query->update(['signature_counter' => max((int) $key->signature_counter, (int) $verifier->getSignatureCounter())]);
            return [$actor, $this->stamp($actor)];
        });
    }
}
