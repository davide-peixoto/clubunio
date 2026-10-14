<?php

namespace App\ClubUnio\Services;

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
        $id = $db->table('clubora.registration_events')->insertGetId([
            'tenant_id' => $tenant->id, 'registration_id' => $r->id, 'revision' => $r->revision,
            'event_key' => $event, 'team_season_id' => $r->team_season_id, 'technical_lead_member_id' => $r->technical_lead_member_id,
            'delivery_state' => $payload ? 'ready' : 'held',
            'payload_encrypted' => $payload ? Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)) : null,
        ]);
        $message = app(MessageTemplateService::class)->render($tenant, $event, 'push', [
            'verein' => $tenant->name, 'mitglied' => trim($r->first_name.' '.$r->last_name),
            'mannschaft' => $db->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('id', $r->team_season_id)->value('label') ?? '',
        ]);
        if (! $message) {
            return;
        }
        $accounts = $db->table('clubora.tenant_accounts as ta')->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')
            ->where('ta.tenant_id', $tenant->id)->where('a.active', true);
        if ($event === 'registration.created') {
            $accounts->whereIn('ta.role', ['office', 'admin']);
        } else {
            $members = app(MemberAccessService::class)->activeMembers($tenant);
            if ($event === 'registration.review') {
                $members->whereKey($r->technical_lead_member_id ?? -1);
                $accounts->where('ta.role', 'technical_lead');
            } elseif (str_starts_with($event, 'registration.staff.') && $r->team_season_id) {
                $members->whereIn('id', $db->table('clubora.team_staff')->where('tenant_id', $tenant->id)->where('team_season_id', $r->team_season_id)->where('active', true)->select('member_id'));
            } else {
                return;
            }
            $accounts->where('ta.portal_enabled', true)->whereIn('ta.account_id', $db->table('clubora.account_member_links')
                ->where('tenant_id', $tenant->id)->where('kind', 'self')->where('state', 'approved')
                ->whereIn('member_id', $members->select('clubora.members.id'))->select('account_id'));
        }
        foreach ($accounts->distinct()->pluck('ta.account_id') as $account) {
            $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account,
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
        $field = $db->table('clubora.billing_recipients')->where('tenant_id', $tenant->id)->where('id', $values['billing_recipient_id'] ?? null)->value('email_field') ?? 'email';
        $to = trim((string) ($values[$field] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $cc = [];
        if ($event === 'registration.trial' && $r->team_season_id) {
            $cc = app(MemberAccessService::class)->activeMembers($tenant)->whereIn('id', $db->table('clubora.team_staff')
                ->where('tenant_id', $tenant->id)->where('team_season_id', $r->team_season_id)->where('active', true)->select('member_id'))
                ->pluck('email')->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        }
        $message = app(MessageTemplateService::class)->render($tenant, $event, 'email', [
            'verein' => $tenant->name, 'mitglied' => trim($r->first_name.' '.$r->last_name),
            'mannschaft' => $db->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('id', $r->team_season_id)->value('label') ?? '',
        ]);

        return $message ? $message + ['intended_to' => $to, 'intended_cc' => $cc] : null;
    }

    public function dispatch(int $limit = 20): array
    {
        return app(TestOutboxDispatcher::class)->dispatch('registration_events', $limit);
    }
}
