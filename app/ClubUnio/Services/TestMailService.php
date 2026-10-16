<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TestMailService
{
    public function sendPreview(Account $actor, Tenant $tenant, int $templateId, string $requestId): string
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', 'send');
        Validator::make(['request_id' => $requestId], ['request_id' => ['required', 'uuid']])->validate();
        if (! config('clubunio-integrations.test_mail_enabled') || ! config('clubunio-integrations.smtp.host') || ! filter_var(config('clubunio-integrations.from'), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['request_id' => 'Der Testversand ist noch nicht konfiguriert.']);
        }
        $db = DB::connection('clubunio');
        $attempt = $db->transaction(function () use ($db, $actor, $tenant, $templateId, $requestId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'templates', 'send');
            $template = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($templateId)->firstOrFail();
            if ($template->channel !== 'email' || ! $template->active) {
                throw ValidationException::withMessages(['request_id' => 'Nur aktive E-Mail-Vorlagen können getestet werden.']);
            }
            $previous = $db->table('clubora.test_mail_attempts')->where('tenant_id', $tenant->id)->where('request_id', $requestId)->first();
            if ($previous) {
                abort_unless((int) $previous->actor_id === (int) $actor->id && (int) $previous->template_id === $templateId, 409);

                return ['existing' => $previous->status];
            }
            if ($db->table('clubora.test_mail_attempts')->where('tenant_id', $tenant->id)->where('actor_id', $actor->id)->where('created_at', '>=', now()->subMinute())->count() >= 5) {
                throw ValidationException::withMessages(['request_id' => 'Maximal fünf Testmails pro Minute. Bitte kurz warten.']);
            }
            $message = app(MessageTemplateService::class)->preview($template->event_key, $template->title, $template->body, MessageEventCatalog::samples());
            $id = $db->table('clubora.test_mail_attempts')->insertGetId(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'template_id' => $template->id, 'revision' => $template->revision, 'request_id' => $requestId,
                'recipient' => TestMailGuard::RECIPIENT, 'status' => 'sending', 'created_at' => now()]);

            return compact('id', 'message');
        });
        if (isset($attempt['existing'])) {
            return $attempt['existing'];
        }
        $status = 'failed';
        try {
            $sent = Mail::mailer('clubunio_test')->raw("Testnachricht aus ClubUnio mit Beispieldaten.\n\n".$attempt['message']['body'], function ($mail) use ($attempt) {
                $mail->to(TestMailGuard::RECIPIENT)->from(config('clubunio-integrations.from'), 'ClubUnio Test')->subject($attempt['message']['title']);
            });
            $status = $sent ? 'accepted' : 'failed';
        } catch (\Throwable) {
            // Provider responses can contain credentials or recipients. Store a fixed code only.
        }
        $db->table('clubora.test_mail_attempts')->where('id', $attempt['id'])->where('tenant_id', $tenant->id)
            ->update(['status' => $status, 'error_code' => $status === 'failed' ? 'SMTP_SEND_FAILED' : null, 'finished_at' => now()]);

        return $status;
    }
}
