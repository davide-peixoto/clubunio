<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Request;

final class LanguagePreferences
{
    public function enabled(): array
    {
        return config('clubunio-languages.enabled', ['de' => 'Deutsch', 'en' => 'English']);
    }

    public function resolve(?string ...$candidates): string
    {
        foreach ([...$candidates, config('clubunio-languages.default', 'de')] as $locale) {
            if ($locale !== null && array_key_exists($locale, $this->enabled())) {
                return $locale;
            }
        }
        return array_key_first($this->enabled()) ?? 'de';
    }

    public function actor(Request $request): ?\App\ClubUnio\Models\Account
    {
        return $request->user('clubunio') ?? $request->user('portal');
    }

    public function setTenantDefault(\App\ClubUnio\Models\Account $actor, \App\ClubUnio\Models\Tenant $tenant, array $input): void
    {
        \Illuminate\Support\Facades\DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$input) {
            $current = \App\ClubUnio\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAdministrationService::class)->allowed($actor,$current),403);
            $data = \Illuminate\Support\Facades\Validator::make($input,[
                'locale'=>['required','string',\Illuminate\Validation\Rule::in(array_keys($this->enabled()))],
                'expected'=>'present|nullable|string', 'reason'=>'required|string|min:10|max:500',
            ])->validate();
            if ($current->default_locale !== $data['expected']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['locale'=>__('Die Sprache wurde inzwischen geändert. Bitte neu öffnen.')]);
            }
            $before = $current->default_locale;
            $current->forceFill(['default_locale'=>$data['locale']])->save();
            \Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id'=>$current->id,'actor_id'=>$actor->id,'object_type'=>'tenants','object_id'=>$current->id,
                'before_value'=>json_encode(['default_locale'=>$before],JSON_THROW_ON_ERROR),
                'after_value'=>json_encode(['default_locale'=>$data['locale'],'reason'=>$data['reason']],JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function returnPath(?string $referer, string $host): string
    {
        $parts = parse_url($referer ?? '');
        if (! is_array($parts) || ($parts['host'] ?? '') !== $host) { return '/'; }
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $decoded = rawurldecode($path);
        return str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//')
            && ! preg_match('/[\\\\\x00-\x1f]/', $decoded) ? $path : '/';
    }
}
