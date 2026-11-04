<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Delivery language never depends on the operator's current UI/session language. */
class MessageLanguageService
{
    public function resolve(Tenant $tenant, ?string $preference = null): string
    {
        return app(LanguagePreferences::class)->resolve($preference, $tenant->default_locale);
    }

    public function account(Tenant $tenant, int $accountId): string
    {
        $preference = $this->accounts($tenant)->where('a.id', $accountId)->value('a.preferred_locale');

        return $this->resolve($tenant, $preference);
    }

    public function forAccounts(Tenant $tenant, array $accountIds): array
    {
        $preferences = $this->accounts($tenant)->whereIn('a.id', $accountIds)->pluck('a.preferred_locale', 'a.id');
        $result = [];
        foreach ($accountIds as $id) {
            $result[$id] = $this->resolve($tenant, $preferences[$id] ?? null);
        }

        return $result;
    }

    public function email(Tenant $tenant, string $email): string
    {
        $preferences = $this->accounts($tenant)->whereRaw('LOWER(TRIM(a.username)) = ?', [mb_strtolower(trim($email))])
            ->pluck('a.preferred_locale')->map(fn ($value) => $this->resolve($tenant, $value))->unique();

        return $preferences->count() === 1 ? $preferences->first() : $this->resolve($tenant);
    }

    private function accounts(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.accounts as a')
            ->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->where('ta.tenant_id', $tenant->id)->where('a.active', true);
    }

    public static function templateLocale(string $language): string
    {
        return match ($language) {
            'de' => 'de-CH', 'en' => 'en-GB', default => $language
        };
    }
}
