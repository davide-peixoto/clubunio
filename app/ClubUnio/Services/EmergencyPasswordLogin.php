<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Log};
use Illuminate\Support\Timebox;

final class EmergencyPasswordLogin
{
    public function expires(Request $request): ?int
    {
        if (!config('clubunio-verein-oidc.only') || !$request->isSecure()
            || $request->getSchemeAndHttpHost() !== config('clubunio-verein-oidc.origin')
            || !is_string($incident = config('clubunio-verein-oidc.emergency.incident'))
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,49}\z/', $incident)
            || !$this->accountIds()) {
            return null;
        }
        $start = $this->timestamp('from');
        $expiry = $this->timestamp('until');
        $now = now()->getTimestamp();

        return $start !== null && $expiry !== null && $start <= $now && $now < $expiry && $expiry - $start <= 3600
            ? $expiry : null;
    }

    private function timestamp(string $key): ?int
    {
        $value = config('clubunio-verein-oidc.emergency.'.$key);
        $format = 'Y-m-d\TH:i:s\Z';
        $date = is_string($value) && strlen($value) === 20 && !str_contains($value, "\0")
            ? DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC')) : false;

        return $date && $date->format($format) === $value ? $date->getTimestamp() : null;
    }

    private function accountIds(): array
    {
        $list = config('clubunio-verein-oidc.emergency.accounts');

        return is_string($list) && preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/', $list)
            ? explode(',', $list) : [];
    }

    public function stamp(Request $request, Account $account): ?string
    {
        $expires = $this->expires($request);
        if (!$expires || !$account->active || $account->api_only
            || !in_array((string) $account->id, $this->accountIds(), true)
            || app(PlatformAdministrationService::class)->allowed($account)) {
            return null;
        }

        return hash_hmac('sha256', json_encode(['clubunio-emergency-password-v1', $account->id, $expires,
            config('clubunio-verein-oidc.emergency.incident'), $account->password], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function authenticate(Request $request, string $username, #[\SensitiveParameter] string $password): array
    {
        return app(Timebox::class)->call(function () use ($request, $username, $password) {
            $account = Account::where('username', $username)->first();
            $stamp = $account ? $this->stamp($request, $account) : null;
            abort_unless($stamp !== null && Hash::check($password, $account->password), 401);

            return [$account, $stamp];
        }, 300_000);
    }

    public function record(Account $account, string $surface): void
    {
        Log::warning('clubunio.emergency_password.used', [
            'account_id' => $account->id, 'surface' => $surface,
            'incident' => config('clubunio-verein-oidc.emergency.incident'),
            'until' => config('clubunio-verein-oidc.emergency.until'),
        ]);
    }
}
