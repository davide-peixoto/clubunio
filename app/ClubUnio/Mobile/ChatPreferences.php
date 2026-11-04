<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\Validator;

final class ChatPreferences
{
    public function get(Account $actor, Tenant $tenant): array
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        $row = app(ChatService::class)->table($tenant, 'chat_preferences')->where('account_id', $actor->id)->first();
        $language = app(MobileLanguages::class)->effective($row->target_language ?? null);
        return ['revision' => (int) ($row->revision ?? 0),
            'translation_enabled' => (bool)($row->translation_enabled ?? false) && !$language['language_fallback'] && $language['language'] !== null,
            'target_language' => $language['language'], 'language_policy' => $language['language_policy'], 'language_fallback' => $language['language_fallback']];
    }

    // The controller holds the tenant/session lock, also used by every chat write.
    public function save(Account $actor, Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:0', 'translation_enabled' => 'required|boolean',
            'target_language' => app(MobileLanguages::class)->rules()])->validate();
        $data['target_language'] = app(MobileLanguages::class)->normalize($data['target_language']);
        $old = $this->get($actor, $tenant);
        abort_unless($old['revision'] === (int) $data['revision'], 409);
        app(ChatService::class)->table($tenant, 'chat_preferences')->updateOrInsert(['tenant_id' => $tenant->id, 'account_id' => $actor->id],
            ['translation_enabled' => $data['translation_enabled'], 'target_language' => $data['target_language'],
                'revision' => $old['revision'] + 1, 'updated_at' => now()->toIso8601String()]);
        return $this->get($actor, $tenant);
    }

    public function allowTranslation(Account $actor, Tenant $tenant, string $language): void
    {
        $language = app(MobileLanguages::class)->validateLanguage($language);
        $preference = $this->get($actor, $tenant);
        abort_unless($preference['translation_enabled'], 403);
        abort_unless($preference['target_language'] === $language, 409);
    }
}
