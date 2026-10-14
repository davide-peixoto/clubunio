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

    public function seed(Tenant $tenant): void
    {
        DB::connection('clubunio')->transaction(function () use ($tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            foreach (MessageEventCatalog::all() as $key => $event) {
                foreach ($event['channels'] as $channel) {
                    if (MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $key)->where('channel', $channel)->where('locale', 'de-CH')->exists()) {
                        continue;
                    }
                    $template = new MessageTemplate;
                    $template->forceFill(['tenant_id' => $tenant->id, 'event_key' => $key, 'channel' => $channel, 'locale' => 'de-CH',
                        'title' => $event['title'], 'body' => $event['body'], 'active' => true, 'revision' => 1])->save();
                    $this->snapshot($template, null);
                }
            }
        });
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $data): MessageTemplate
    {
        abort_unless($actor->canAccessTenant($tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $template = $id ? MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($id)->lockForUpdate()->firstOrFail() : new MessageTemplate;
            if ($id && (int) ($data['revision'] ?? 0) !== $template->revision) {
                throw ValidationException::withMessages(['title' => 'Die Vorlage wurde inzwischen geändert. Bitte neu öffnen.']);
            }
            $values = $id ? array_merge($data, $template->only(['event_key', 'channel', 'locale'])) : $data;
            Validator::make($values, [
                'event_key' => ['required', Rule::in(array_keys(MessageEventCatalog::all()))],
                'channel' => ['required', Rule::in(['email', 'push'])],
                'locale' => ['required', Rule::in(array_keys(self::LOCALES))],
                'title' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
                'body' => ['required', 'string', 'max:10000'], 'active' => ['required', 'boolean'],
            ])->validate();
            $definition = MessageEventCatalog::get($values['event_key']);
            if (! in_array($values['channel'], $definition['channels'], true)) {
                throw ValidationException::withMessages(['channel' => 'Dieser Kanal ist für das Ereignis nicht vorgesehen.']);
            }
            foreach (['title', 'body'] as $field) {
                $this->validateText($values['event_key'], $values[$field], $field);
            }
            if (! $id && MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $values['event_key'])->where('channel', $values['channel'])->where('locale', $values['locale'])->exists()) {
                throw ValidationException::withMessages(['locale' => 'Für dieses Ereignis, diesen Kanal und diese Sprache besteht bereits eine Vorlage.']);
            }
            $template->forceFill(array_intersect_key($values, array_flip(['event_key', 'channel', 'locale', 'title', 'body', 'active'])));
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
        abort_unless($actor->canAccessTenant($tenant), 403);
        $old = DB::connection('clubunio')->table('clubora.message_template_revisions')->where('tenant_id', $tenant->id)->where('template_id', $id)->where('revision', $revision)->first();
        abort_unless($old, 404);

        return $this->save($actor, $tenant, $id, ['revision' => $expectedRevision, 'title' => $old->title, 'body' => $old->body, 'active' => $old->active]);
    }

    public function render(Tenant $tenant, string $event, string $channel, array $values, string $locale = 'de-CH'): ?array
    {
        MessageEventCatalog::get($event);
        $query = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_key', $event)->where('channel', $channel);
        $template = (clone $query)->where('locale', $locale)->first() ?? (clone $query)->where('locale', 'de-CH')->first();
        // An explicitly disabled translation must not silently fall back to another language.
        if (! $template || ! $template->active) {
            return null;
        }

        return $this->preview($event, $template->title, $template->body, $values) + ['template_id' => $template->id, 'revision' => $template->revision, 'locale' => $template->locale];
    }

    public function preview(string $event, string $title, string $body, array $values): array
    {
        $result = [];
        foreach (compact('title', 'body') as $field => $text) {
            $this->validateText($event, $text, $field);
            $result[$field] = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($match) use ($values, $field) {
                if (! array_key_exists($match[1], $values) || ! is_scalar($values[$match[1]])) {
                    throw ValidationException::withMessages([$field => 'Ein erforderlicher Platzhalterwert fehlt: '.$match[1]]);
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
            throw ValidationException::withMessages([$field => 'Nur Klartext und die zugelassenen Platzhalter sind erlaubt.']);
        }
    }

    private function snapshot(MessageTemplate $template, ?int $actor): void
    {
        DB::connection('clubunio')->table('clubora.message_template_revisions')->insert($template->only(['tenant_id', 'revision', 'title', 'body', 'active']) + ['template_id' => $template->id, 'actor_id' => $actor]);
    }
}
