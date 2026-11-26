<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessageTemplateService
{
    public const LOCALES = ['de-CH' => 'Deutsch (Schweiz)', 'de-DE' => 'Deutsch', 'fr-CH' => 'Französisch', 'it-CH' => 'Italienisch', 'en-GB' => 'Englisch'];

    public static function locales(): array
    {
        $locales = [];
        foreach (config('clubunio-languages.enabled', ['de' => 'Deutsch', 'en' => 'English']) as $language => $label) {
            $locales[MessageLanguageService::templateLocale($language)] = __($label);
        }

        return $locales;
    }

    public function seed(Tenant $tenant): void
    {
        DB::connection('clubunio')->transaction(function () use ($tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            foreach (array_keys(config('clubunio-languages.enabled', ['de' => '', 'en' => ''])) as $language) {
                $locale = MessageLanguageService::templateLocale($language);
                foreach (MessageEventCatalog::all($language) as $key => $event) {
                    foreach ($event['channels'] as $channel) {
                        $variants = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $key)->where('channel', $channel);
                        if ((clone $variants)->where('locale', $locale)->exists()) {
                            continue;
                        }
                        $template = new MessageTemplate;
                        $active = (clone $variants)->where('locale', 'de-CH')->value('active') ?? true;
                        $template->forceFill(['tenant_id' => $tenant->id, 'event_key' => $key, 'channel' => $channel, 'locale' => $locale,
                            'title' => $event['title'], 'body' => $event['body'], 'active' => $active, 'revision' => 1])->save();
                        $this->snapshot($template, null);
                    }
                }
            }
        });
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $data): MessageTemplate
    {
        return $this->persist($actor, $tenant, $id, $data, $id ? 'update' : 'create');
    }

    private function persist(Account $actor, Tenant $tenant, ?int $id, array $data, string $function): MessageTemplate
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', $function);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data, $function) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $template = $id ? MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail() : new MessageTemplate;
            if ($id && (int) ($data['revision'] ?? 0) !== $template->revision) {
                throw ValidationException::withMessages(['title' => __('Die Vorlage wurde inzwischen geändert. Bitte neu öffnen.')]);
            }
            $values = $id ? array_merge($data, $template->only(['event_key', 'channel', 'locale'])) : $data;
            Validator::make($values, [
                'event_key' => ['required', Rule::in(array_keys(MessageEventCatalog::all()))],
                'channel' => ['required', Rule::in(['email', 'push'])],
                'locale' => ['required', Rule::in($id ? [$template->locale] : array_keys(self::locales()))],
                'title' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
                'body' => ['required', 'string', 'max:10000'], 'active' => ['required', 'boolean'],
            ])->validate();
            if ($id && $values['active'] && ! $template->active && ! array_key_exists($template->locale, self::locales())) {
                throw ValidationException::withMessages(['active' => __('Diese Vorlagensprache ist nicht aktiviert.')]);
            }
            $definition = MessageEventCatalog::get($values['event_key']);
            if (! in_array($values['channel'], $definition['channels'], true)) {
                throw ValidationException::withMessages(['channel' => __('Dieser Kanal ist für das Ereignis nicht vorgesehen.')]);
            }
            foreach (['title', 'body'] as $field) {
                $this->validateText($values['event_key'], $values[$field], $field);
            }
            if (! $id && MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $values['event_key'])->where('channel', $values['channel'])->where('locale', $values['locale'])->exists()) {
                throw ValidationException::withMessages(['locale' => __('Für dieses Ereignis, diesen Kanal und diese Sprache besteht bereits eine Vorlage.')]);
            }
            $template->forceFill(array_intersect_key($values, array_flip(['event_key', 'channel', 'locale', 'title', 'body', 'active'])));
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', $function);
            if ($id && $template->isDirty('active')) {
                app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', $template->active ? 'restore' : 'deactivate');
            }
            if ($id && ! $template->isDirty()) {
                return $template;
            }
            $template->forceFill(['tenant_id' => $tenant->id, 'revision' => $id ? $template->revision + 1 : 1])->save();
            $this->snapshot($template, $actor->id);

            return $template;
        });
    }

    public function restore(Account $actor, Tenant $tenant, int $id, int $revision, int $expectedRevision): MessageTemplate
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', 'restore');
        $old = DB::connection('clubunio')->table('clubunio.message_template_revisions')->where('tenant_id', $tenant->id)->where('template_id', $id)->where('revision', $revision)->first();
        abort_unless($old, 404);

        return $this->persist($actor, $tenant, $id, ['revision' => $expectedRevision, 'title' => $old->title, 'body' => $old->body, 'active' => $old->active], 'restore');
    }

    public function render(Tenant $tenant, string $event, string $channel, array $values, ?string $locale = null): ?array
    {
        MessageEventCatalog::get($event);
        $locale = MessageLanguageService::templateLocale($locale ?? app(MessageLanguageService::class)->resolve($tenant));
        $query = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $event)->where('channel', $channel);
        $template = (clone $query)->where('locale', $locale)->first() ?? (clone $query)->where('locale', 'de-CH')->first();
        // An explicitly disabled translation must not silently fall back to another language.
        if (! $template || ! $template->active) {
            return null;
        }
        foreach ($values as &$value) {
            if ($value instanceof MessageLookupValue) {
                $value = $value->resolve($template->locale, $tenant->id);
            }
        }
        unset($value);

        return $this->preview($event, $template->title, $template->body, $values) + ['template_id' => $template->id, 'revision' => $template->revision, 'locale' => $template->locale];
    }

    public function renderForAccount(Tenant $tenant, string $event, string $channel, array $values, int $accountId): ?array
    {
        return $this->render($tenant, $event, $channel, $values, app(MessageLanguageService::class)->account($tenant, $accountId));
    }

    public function renderForEmail(Tenant $tenant, string $event, array $values, string $email): ?array
    {
        return $this->render($tenant, $event, 'email', $values, app(MessageLanguageService::class)->email($tenant, $email));
    }

    /** Resolve only the supplied recipient IDs; this never adds or authorizes recipients. */
    public function renderForAccounts(Tenant $tenant, string $event, string $channel, array $values, array $accountIds): array
    {
        $languages = app(MessageLanguageService::class)->forAccounts($tenant, $accountIds);
        $variants = $messages = [];
        foreach ($languages as $accountId => $language) {
            if (! array_key_exists($language, $variants)) {
                $variants[$language] = $this->render($tenant, $event, $channel, $values, $language);
            }
            $messages[$accountId] = $variants[$language];
        }

        return $messages;
    }

    public function preview(string $event, string $title, string $body, array $values): array
    {
        $result = [];
        foreach (compact('title', 'body') as $field => $text) {
            $this->validateText($event, $text, $field);
            $result[$field] = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($match) use ($values, $field) {
                if (! array_key_exists($match[1], $values) || ! is_scalar($values[$match[1]])) {
                    throw ValidationException::withMessages([$field => __('Ein erforderlicher Platzhalterwert fehlt: :placeholder', ['placeholder' => $match[1]])]);
                }

                return $field === 'title' ? str_replace(["\r", "\n"], ' ', (string) $values[$match[1]]) : (string) $values[$match[1]];
            }, $text);
        }

        return $result;
    }

    private function validateText(string $event, string $text, string $field): void
    {
        $allowed = MessageEventCatalog::get($event)['variables'];
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
        $remaining = preg_replace('/\{\{\s*([a-z_]+)\s*\}\}/', '', $text);
        if (array_diff($matches[1], $allowed) || str_contains($remaining, '{') || str_contains($remaining, '}') || preg_match('/<[^>]*>|@(?:php|include|extends|if|foreach)\b/i', $text)) {
            throw ValidationException::withMessages([$field => __('Nur Klartext und die zugelassenen Platzhalter sind erlaubt.')]);
        }
    }

    private function snapshot(MessageTemplate $template, ?int $actor): void
    {
        DB::connection('clubunio')->table('clubunio.message_template_revisions')->insert($template->only(['tenant_id', 'revision', 'title', 'body', 'active']) + ['template_id' => $template->id, 'actor_id' => $actor]);
    }
}
