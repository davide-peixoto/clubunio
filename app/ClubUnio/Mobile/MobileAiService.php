<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class MobileAiService
{
    private function source(Account $actor, Tenant $tenant, array $data): array
    {
        if (request()->attributes->has('mobile.session')) {
            app(MobileSessions::class)->authenticate(request());
        }
        app(MobileAccess::class)->enter($actor, $tenant);
        if (! empty($data['message_id'])) {
            $s = app(ChatService::class);
            $row = $s->table($tenant, 'chat_messages')->where('id', $data['message_id'])->whereNull('deleted_at')->first();
            abort_unless($row, 404);
            $s->chat($actor, $tenant, $row->conversation_id);
            $membership = $s->table($tenant, 'chat_memberships')->where('conversation_id', $row->conversation_id)->where('account_id', $actor->id)->first();
            abort_unless($membership && $row->id > $membership->joined_after, 404);
            abort_unless($data['operation'] === 'translate', 422);
            return [Crypt::decryptString($row->body_encrypted), (int) $row->revision];
        }
        abort_unless(isset($data['text']) && trim($data['text']) !== '', 422);
        return [$data['text'], null];
    }

    public function execute(Account $actor, Tenant $tenant, array $input): array
    {
        abort_unless(config('clubunio-mobile.ai.enabled'), 503);
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'operation' => 'required|in:translate,correct,friendly,shorten,check',
            'language' => 'required|in:de,en,fr,it,pt-PT,sq,sr,tr', 'message_id' => 'nullable|integer|min:1',
            'text' => 'nullable|string|max:4000', 'consent' => 'required|accepted'])->validate();
        [$text, $revision] = $this->source($actor, $tenant, $data);
        $provider = config('clubunio-mobile.ai.'.($data['operation'] === 'translate' ? 'translation_provider' : 'writing_provider'));
        abort_unless(in_array($provider, ['openai', 'azure_openai', 'azure_translator'], true), 503);
        abort_if($provider === 'azure_translator' && $data['operation'] !== 'translate', 422);
        $fingerprint = hash('sha256', json_encode([$data['operation'], $text, $revision, $data['language'], $data['message_id'] ?? null, 'prompt-v1'], JSON_THROW_ON_ERROR));
        $db = DB::connection('clubunio');
        $work = $db->transaction(function () use ($db, $tenant, $actor, $data, $fingerprint, $provider, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(MobileAccess::class)->enter($actor, $tenant);
            $query = $db->table('clubora.mobile_ai_work')->where('tenant_id', $tenant->id);
            $old = (clone $query)->where('account_id', $actor->id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409);
                abort_unless($old->state === 'succeeded', 409);
                return $old;
            }
            // Reuse only translations of authorized messages, not another account's private draft.
            if (! empty($data['message_id'])) {
                $cached = (clone $query)->where('fingerprint', $fingerprint)->where('state', 'succeeded')->first();
                if ($cached) { return $cached; }
            }
            $spent = (clone $query)->where('created_at', '>=', now()->startOfMonth()->toIso8601String())->get()
                ->sum(fn ($r) => $r->actual_microusd ?? $r->reserved_microusd);
            if ($provider === 'openai') {
                abort_unless(config('clubunio-mobile.ai.openai.model') === 'gpt-5.4-mini', 503);
                $reserve = 25_000; // 4000 characters + prompt, at most 1000 output tokens.
            } else {
                $reserve = (int) config('clubunio-mobile.ai.azure_reservation_microusd');
                abort_unless($reserve > 0, 503); // Deployment-specific price must be reviewed first.
            }
            abort_if($spent + $reserve > (int) config('clubunio-mobile.ai.monthly_limit_microusd'), 429);
            $id = (string) Str::uuid();
            $query->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'request_key' => $data['request_key'],
                'fingerprint' => $fingerprint, 'provider' => $provider, 'operation' => $data['operation'], 'message_id' => $data['message_id'] ?? null,
                'target_language' => $data['language'], 'revision' => $revision, 'state' => 'running', 'reserved_microusd' => $reserve, 'created_at' => now()->toIso8601String()]);
            return $query->where('id', $id)->first();
        });
        if ($work->state === 'succeeded') {
            $this->source($actor, $tenant, $data);
            return ['text' => Crypt::decryptString($work->result_encrypted), 'language' => $data['language'], 'cached' => true];
        }
        try {
            $adapter = match ($work->provider) {
                'openai' => new OpenAiTextProvider,
                'azure_openai' => new OpenAiTextProvider(true),
                'azure_translator' => new AzureTranslatorProvider,
            };
            $result = $adapter->transform($data['operation'], $text, $data['language']);
            [$freshText, $freshRevision] = $this->source($actor, $tenant, $data);
            abort_unless($freshRevision === $revision && hash_equals($freshText, $text), 409);
            $actual = $work->provider === 'openai' && config('clubunio-mobile.ai.openai.model') === 'gpt-5.4-mini'
                ? (int) ceil($result['input_tokens'] * 0.75 + $result['output_tokens'] * 4.5) : $work->reserved_microusd;
            $db->table('clubora.mobile_ai_work')->where('id', $work->id)->update(['state' => 'succeeded', 'result_encrypted' => Crypt::encryptString($result['text']),
                'actual_microusd' => $actual, 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'model' => $result['model']]);
            return ['text' => $result['text'], 'language' => $data['language'], 'cached' => false];
        } catch (\Throwable $e) {
            $db->table('clubora.mobile_ai_work')->where('id', $work->id)->update(['state' => 'attention']);
            throw $e;
        }
    }
}
