<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RegistrationMessageService
{
    public function record(Tenant $tenant, Registration $r, string $event): void
    {
        $payload = $this->prepare($tenant, $r, $event);
        $db = DB::connection('clubunio');
        $id = $db->table('clubunio.registration_events')->insertGetId([
            'tenant_id' => $tenant->id, 'registration_id' => $r->id, 'revision' => $r->revision,
            'event_key' => $event, 'team_season_id' => $r->team_season_id, 'technical_lead_member_id' => $r->technical_lead_member_id,
            'delivery_state' => $payload ? 'ready' : 'held',
            'payload_encrypted' => $payload ? Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)) : null,
        ]);
        $messageValues = [
            'verein' => $tenant->name, 'mitglied' => trim($r->first_name.' '.$r->last_name),
            'mannschaft' => $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->where('id', $r->team_season_id)->value('label') ?? '',
        ];
        $accounts = $db->table('clubunio.tenant_accounts as ta')->join('clubunio.accounts as a', 'a.id', '=', 'ta.account_id')
            ->where('ta.tenant_id', $tenant->id)->where('a.active', true);
        if ($event === 'registration.created') {
            $accounts->whereIn('ta.role', ['office', 'admin']);
        } else {
            $members = app(MemberAccessService::class)->activeMembers($tenant);
            if ($event === 'registration.review') {
                $members->whereKey($r->technical_lead_member_id ?? -1);
                $accounts->where('ta.role', 'technical_lead');
            } elseif (str_starts_with($event, 'registration.staff.') && $r->team_season_id) {
                $members->whereIn('id', $db->table('clubunio.team_staff')->where('tenant_id', $tenant->id)->where('team_season_id', $r->team_season_id)->where('active', true)->select('member_id'));
            } else {
                return;
            }
            $accounts->where('ta.portal_enabled', true)->whereIn('ta.account_id', $db->table('clubunio.account_member_links')
                ->where('tenant_id', $tenant->id)->where('kind', 'self')->where('state', 'approved')
                ->whereIn('member_id', $members->select('clubunio.members.id'))->select('account_id'));
        }
        foreach ($accounts->distinct()->pluck('ta.account_id') as $account) {
            $message = app(MessageTemplateService::class)->renderForAccount($tenant, $event, 'push', $messageValues, $account);
            if (! $message) {
                continue;
            }
            $db->table('clubunio.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account,
                'registration_event_id' => $id, 'title' => $message['title'], 'body' => $message['body'],
                'object_type' => 'registration', 'object_id' => $r->id]);
        }
    }

    public function prepare(Tenant $tenant, Registration $r, string $event): ?array
    {
        if (! in_array($event, ['registration.received', 'registration.trial', 'registration.accepted', 'registration.rejected', 'registration.pool'], true)) {
            return null;
        }
        $values = app(RegistrationDataService::class)->values($r);
        $db = DB::connection('clubunio');
        $field = $db->table('clubunio.billing_recipients')->where('tenant_id', $tenant->id)->where('id', $values['billing_recipient_id'] ?? null)->value('email_field') ?? 'email';
        $to = trim((string) ($values[$field] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $cc = [];
        if ($event === 'registration.trial' && $r->team_season_id) {
            $cc = app(MemberAccessService::class)->activeMembers($tenant)->whereIn('id', $db->table('clubunio.team_staff')
                ->where('tenant_id', $tenant->id)->where('team_season_id', $r->team_season_id)->where('active', true)->select('member_id'))
                ->pluck('email')->map(fn ($email) => mb_strtolower(trim($email ?? '')))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== mb_strtolower($to))->unique()->values()->all();
        }
        $messageValues = [
            'verein' => $tenant->name, 'mitglied' => trim($r->first_name.' '.$r->last_name),
            'mannschaft' => $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->where('id', $r->team_season_id)->value('label') ?? '',
        ];
        $templates = app(MessageTemplateService::class);
        $message = $templates->renderForEmail($tenant, $event, $messageValues, $to);
        if (! $message) {
            return null;
        }
        $deliveries = [$message + ['intended_to' => $to, 'intended_cc' => []]];
        foreach ($cc as $email) {
            $copy = $templates->renderForEmail($tenant, $event, $messageValues, $email);
            if ($copy) {
                $deliveries[] = $copy + ['intended_to' => [], 'intended_cc' => [$email]];
            }
        }

        return $message + ['intended_to' => $to, 'intended_cc' => $cc, 'deliveries' => $deliveries];
    }

    public function dispatch(int $limit = 20): array
    {
        return app(TestOutboxDispatcher::class)->dispatch('registration_events', $limit);
    }

    public function dispatchOne(Account $actor, Tenant $tenant, int $registration, int $event): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'registrations', 'send');
        abort_unless(DB::connection('clubunio')->table('clubunio.registration_events')->where('tenant_id', $tenant->id)
            ->where('registration_id', $registration)->where('id', $event)->where('delivery_state', 'ready')->exists(), 404);
        DB::connection('clubunio')->table('clubunio.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'registration_events', 'object_id' => $event,
            'after_value' => json_encode(['operation' => 'test_mail_requested', 'registration_id' => $registration], JSON_THROW_ON_ERROR)]);

        return app(TestOutboxDispatcher::class)->dispatch('registration_events', 1, $tenant->id, $event);
    }
}
