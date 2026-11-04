<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\{Crypt, DB};

final class MobileAdminNoticeService
{
    private function db() { return DB::connection('clubunio'); }
    private function access(): MobileAdminNoticeAccess { return app(MobileAdminNoticeAccess::class); }
    private function payload(array $data): array
    {
        return ['target_type' => $data['target_type'], 'target_value' => isset($data['target_value']) ? trim($data['target_value']) : null,
            'title' => trim($data['title']), 'body' => trim($data['body'])];
    }
    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); }

    public function options(Account $actor, Tenant $tenant): array
    {
        $this->access()->authorize($actor, $tenant);
        return ['canSend' => true, 'tenantId' => (int) $tenant->id, 'tenantName' => $tenant->name,
            'roles' => collect(MobileAdminNoticeAccess::ROLES)->map(fn ($label, $value) => compact('value', 'label'))->values()->all()];
    }

    public function lookup(Account $actor, Tenant $tenant, string $type, string $search): array
    {
        $this->access()->authorize($actor, $tenant);
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
        if ($type === 'member') {
            return app(MemberAccessService::class)->activeMembers($tenant)->where(function ($query) use ($search, $pattern) {
                $query->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern);
                if (ctype_digit($search) && strlen($search) < 19) $query->orWhere('id', (int) $search);
            })->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(20)->get(['id', 'first_name', 'last_name'])
                ->map(fn ($member) => ['value' => (string) $member->id, 'label' => trim($member->first_name.' '.$member->last_name), 'subtitle' => 'Mitglied '.$member->id])->all();
        }
        $result = [];
        $candidates = Account::query()->where('active', true)->where('api_only', false)
            ->whereIn('id', $this->db()->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->whereIn('role', array_keys(MobileAdminNoticeAccess::ROLES))->select('account_id'))
            ->where(fn ($query) => $query->where('display_name', 'ilike', $pattern)->orWhere('username', 'ilike', $pattern))
            ->orderBy('display_name')->orderBy('id');
        foreach ($candidates->cursor() as $candidate) {
            if (! $this->access()->matches($candidate, $tenant, 'all', null)) continue;
            $result[] = ['value' => (string) $candidate->id, 'label' => $candidate->display_name, 'subtitle' => 'Konto '.$candidate->id];
            if (count($result) === 20) break;
        }
        return $result;
    }

    private function audience(Tenant $tenant, array $data): array
    {
        if ($data['target_type'] === 'member') app(MemberAccessService::class)->activeMembers($tenant)->whereKey((int) $data['target_value'])->firstOrFail();
        if ($data['target_type'] === 'account') {
            abort_unless($this->db()->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', (int) $data['target_value'])->exists(), 404);
        }
        $memberships = $this->db()->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->whereIn('role', array_keys(MobileAdminNoticeAccess::ROLES));
        if ($data['target_type'] === 'office') $memberships->where('role', 'office');
        elseif ($data['target_type'] === 'trainer_office') $memberships->whereIn('role', ['trainer', 'office']);
        elseif ($data['target_type'] === 'role') $memberships->where('role', $data['target_value']);
        if ($data['target_type'] === 'member') $memberships->whereIn('account_id', $this->db()->table('clubora.account_member_links')
            ->where('tenant_id', $tenant->id)->where('member_id', (int) $data['target_value'])->where('state', 'approved')->select('account_id'));
        $query = Account::query()->where('active', true)->where('api_only', false)
            ->whereIn('id', $memberships->select('account_id'))
            ->when($data['target_type'] === 'account', fn ($query) => $query->whereKey((int) $data['target_value']))->orderBy('id');
        $rows = [];
        foreach ($query->cursor() as $candidate) {
            if (! $this->access()->matches($candidate, $tenant, $data['target_type'], $data['target_value'])) continue;
            $membership = app(MemberAccessService::class)->membership($candidate, $tenant);
            $rows[] = ['id' => (int) $candidate->id, 'role' => $membership->role, 'revision' => (int) $membership->revision];
            abort_if(count($rows) > 10000, 422, 'Die Zielgruppe ist zu gross. Bitte enger auswählen.');
        }
        return $rows;
    }

    public function preview(Account $actor, Tenant $tenant, array $input): array
    {
        $this->access()->authorize($actor, $tenant); $payload = $this->payload($input); $rows = $this->audience($tenant, $payload);
        $sender = app(MemberAccessService::class)->membership($actor, $tenant);
        return ['tenantId' => (int) $tenant->id, 'tenantName' => $tenant->name, 'recipientCount' => count($rows),
            'revision' => $this->hash([$tenant->id, $actor->id, $sender->role, (int) $sender->revision, $payload, $rows])];
    }

    /** Called within the guarded tenant/session transaction; publishes identifiers only. */
    public function create(Account $actor, Tenant $tenant, array $input): array
    {
        $this->access()->authorize($actor, $tenant); $payload = $this->payload($input); $hash = $this->hash($payload);
        $old = $this->db()->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('command_key', $input['request_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_sha256, $hash), 409, 'Dieser Auftrag gehört zu einem anderen Inhalt.');
            return $this->receipt($actor, $tenant, (int) $old->id) + ['replayed' => true];
        }
        $rows = $this->audience($tenant, $payload); $sender = app(MemberAccessService::class)->membership($actor, $tenant);
        $revision = $this->hash([$tenant->id, $actor->id, $sender->role, (int) $sender->revision, $payload, $rows]);
        abort_unless(hash_equals($input['revision'], $revision), 409, 'Empfänger oder Inhalt wurden geändert. Bitte Vorschau neu laden.');
        abort_if(! $rows, 422, 'Keine aktuell berechtigten Empfänger.');
        $id = $this->db()->table('clubora.mobile_admin_notices')->insertGetId(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
            'command_key' => $input['request_key'], 'payload_sha256' => $hash, 'recipient_sha256' => $this->hash(array_column($rows, 'id')),
            'target_type' => $payload['target_type'], 'target_value' => $payload['target_value'],
            'title_encrypted' => Crypt::encryptString($payload['title']), 'body_encrypted' => Crypt::encryptString($payload['body']),
            'created_at' => now()->toIso8601String()]);
        $this->db()->table('clubora.mobile_admin_notice_recipients')->insert(array_map(fn ($row) => ['tenant_id' => $tenant->id, 'notice_id' => $id, 'account_id' => $row['id']], $rows));
        $this->publish($tenant, $id);
        return $this->receipt($actor, $tenant, $id) + ['replayed' => false];
    }

    /** No provider access. Existing snapshots alone select recipients; retries are idempotent. */
    public function publish(Tenant $tenant, int $id): int
    {
        if (! $this->access()->publicationCurrent($tenant, $id)) return 0;
        $notice = $this->db()->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $ids = $this->db()->table('clubora.mobile_admin_notice_recipients')->where('tenant_id', $tenant->id)->where('notice_id', $id)->select('account_id');
        $count = 0;
        foreach (Account::whereIn('id', $ids)->orderBy('id')->cursor() as $recipient) {
            if (! $this->access()->matches($recipient, $tenant, $notice->target_type, $notice->target_value)) continue;
            $this->db()->table('clubora.mobile_inbox')->insertOrIgnore(['tenant_id' => $tenant->id, 'account_id' => $recipient->id,
                'kind' => 'admin_notice', 'object_id' => $id, 'event_key' => 'admin.notice:'.$id, 'created_at' => $notice->created_at]);
            $count += app(MobilePushOutbox::class)->enqueue($recipient, $tenant, 'admin_notice', $id, 'admin.notice:'.$id, $notice->created_at);
        }
        if (config('clubunio-mobile-push.enabled', false)) {
            $this->db()->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('id', $id)->update(['published_at' => now()->toIso8601String()]);
        }
        return $count;
    }

    /** Explicit existing push worker only; the source window prevents a historical flood. */
    public function pending(): int
    {
        if (! config('clubunio-mobile.native_push_admin_enabled', false) || ! config('clubunio-mobile-push.enabled', false)) return 0;
        $count = 0;
        $this->db()->table('clubora.mobile_admin_notices')->where('created_at', '>', now()->subDay()->toIso8601String())
            ->select(['id', 'tenant_id'])->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $tenant = Tenant::find($row->tenant_id);
                    if ($tenant) $count += $this->publish($tenant, (int) $row->id);
                }
            });
        return $count;
    }

    public function receipt(Account $actor, Tenant $tenant, int $id): array
    {
        $this->access()->authorize($actor, $tenant);
        $notice = $this->db()->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('id', $id)->firstOrFail();
        $states = $this->db()->table('clubora.mobile_push_outbox')->where('tenant_id', $tenant->id)->where('kind', 'admin_notice')->where('object_id', $id)
            ->groupBy('state')->selectRaw('state,COUNT(*) AS count')->pluck('count', 'state');
        $devices = (int) $states->sum();
        $state = ! config('clubunio-mobile-push.enabled', false) ? 'disabled' : ($notice->published_at === null ? 'queued' : ($devices === 0 ? 'noDevices'
            : (($states['attention'] ?? 0) > 0 ? 'attention' : ((($states['pending'] ?? 0) + ($states['sending'] ?? 0)) > 0 ? 'queued'
                : (($states['accepted'] ?? 0) > 0 ? 'accepted' : 'discarded')))));
        return ['success' => true, 'noticeId' => $id, 'tenantId' => (int) $tenant->id, 'tenantName' => $tenant->name,
            'recipientCount' => $this->db()->table('clubora.mobile_admin_notice_recipients')->where('tenant_id', $tenant->id)->where('notice_id', $id)->count(),
            'deviceCount' => $devices, 'state' => $state];
    }
}
