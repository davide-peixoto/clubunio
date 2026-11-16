<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, RateLimiter};

/** Read-only compatibility for existing factors and session stamps. */
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

    public function configured(Account $actor): bool
    {
        return $this->enabled() && ($this->settings()->where('account_id', $actor->id)->whereNotNull('totp_encrypted')->exists()
            || $this->keys()->where('account_id', $actor->id)->exists());
    }

    public function mustEnroll(Account $actor): bool
    {
        $cutoff = config('clubunio-security.enroll_since');
        return $this->enabled() && $actor->active && !$actor->api_only && is_string($cutoff)
            && $actor->created_at && $actor->created_at->gte(\Illuminate\Support\Carbon::parse($cutoff))
            && !$this->configured($actor);
    }

    public function requiresStrongLogin(Account $actor): bool
    {
        return $this->configured($actor) || $this->mustEnroll($actor);
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

    private function hash(#[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
