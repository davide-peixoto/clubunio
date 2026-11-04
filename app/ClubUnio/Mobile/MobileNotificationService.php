<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

/** Personal original-screen inbox; provider opt-in never controls reading owned notices. */
final class MobileNotificationService
{
    private const SOURCES = ['expense' => 'expense', 'registration' => 'registration', 'bus_reservation' => 'bus',
        'member_change_request' => 'member_change', 'team_event' => 'planning'];

    private function rows(Account $actor, Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.mobile_inbox')->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
    }

    /** References real, currently visible owned source rows, never copies private payloads. */
    private function materialize(Account $actor, Tenant $tenant): void
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
            ->whereIn('object_type', array_keys(self::SOURCES))->select(['id', 'object_type', 'object_id', 'created_at', 'read_at'])
            ->chunkById(100, function ($rows) use ($actor, $tenant) {
                foreach ($rows as $row) {
                    $kind = self::SOURCES[$row->object_type];
                    if (! app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $kind, (int) $row->object_id, false)) continue;
                    $this->rows($actor, $tenant)->insertOrIgnore(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                        'kind' => $kind, 'object_id' => $row->object_id, 'event_key' => 'notification:'.$row->id,
                        'read_at' => $row->read_at, 'created_at' => $row->created_at]);
                    if ($row->read_at !== null) $this->rows($actor, $tenant)->where('event_key', 'notification:'.$row->id)->whereNull('read_at')->update(['read_at' => $row->read_at]);
                }
            });
    }

    private function visible(Account $actor, Tenant $tenant): array
    {
        $this->materialize($actor, $tenant);
        $visible = [];
        foreach ($this->rows($actor, $tenant)->orderByDesc('id')->cursor() as $row) {
            if (app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $row->kind, (int) $row->object_id, false)) $visible[] = $row;
        }
        return $visible;
    }

    private function text(Account $actor, Tenant $tenant, object $row): array
    {
        if ($row->kind === 'admin_notice') {
            $notice = app(MobileAdminNoticeAccess::class)->notice($actor, $tenant, (int) $row->object_id);
            return [Crypt::decryptString($notice->title_encrypted), Crypt::decryptString($notice->body_encrypted)];
        }
        if ($row->kind === 'penalty') {
            $notice = app(MobilePenaltyAccess::class)->notice($actor, $tenant, (int) $row->object_id);
            return [Crypt::decryptString($notice->title_encrypted), Crypt::decryptString($notice->body_encrypted)];
        }
        if (preg_match('/^notification:([1-9][0-9]*)$/D', $row->event_key, $matches)) {
            $source = DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                ->where('id', $matches[1])->where('object_id', $row->object_id)->first(['object_type', 'title', 'body']);
            if ($source && (self::SOURCES[$source->object_type] ?? null) === $row->kind) return [$source->title, $source->body];
        }
        return match ($row->kind) {
            'invoice' => ['Neue Rechnung', 'Eine Rechnung ist verfügbar.'],
            'planning' => ['Aufgebot', 'Das Aufgebot ist verfügbar.'],
            'announcement' => ['Vereinsankündigung', 'Eine Vereinsankündigung ist verfügbar.'],
            default => ['Neue Mitteilung', 'Weitere Informationen sind im zugehörigen Eintrag verfügbar.'],
        };
    }

    public function page(Account $actor, Tenant $tenant, int $start, int $limit, bool $unreadOnly): array
    {
        $all = $this->visible($actor, $tenant);
        $unread = count(array_filter($all, fn ($r) => $r->read_at === null));
        $selected = $unreadOnly ? array_values(array_filter($all, fn ($r) => $r->read_at === null)) : $all;
        $rows = [];
        foreach (array_slice($selected, $start, $limit) as $row) {
            [$title, $body] = $this->text($actor, $tenant, $row);
            $rows[] = ['NotificationID' => (int) $row->id, 'title' => $title, 'body' => $body, 'objectType' => $row->kind,
                'objectId' => (int) $row->object_id, 'data' => ['tenant_id' => (int) $tenant->id, 'kind' => $row->kind, 'object_id' => (int) $row->object_id],
                'readAt' => $row->read_at, 'createdAt' => $row->created_at];
        }
        return ['notifications' => $rows, 'unreadCount' => $unread, 'totalRecordCount' => count($selected), 'watermark' => (int) ($all[0]->id ?? 0)];
    }

    public function count(Account $actor, Tenant $tenant): array
    {
        $all = $this->visible($actor, $tenant);
        return ['unreadCount' => count(array_filter($all, fn ($r) => $r->read_at === null)), 'watermark' => (int) ($all[0]->id ?? 0)];
    }

    public function read(Account $actor, Tenant $tenant, ?int $id, ?int $through): array
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $through) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $rows = $id ? $this->rows($actor, $tenant)->where('id', $id)->get() : $this->rows($actor, $tenant)->where('id', '<=', $through)->whereNull('read_at')->get();
            if ($id && $rows->isEmpty()) abort(404);
            foreach ($rows as $row) {
                if (! app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $row->kind, (int) $row->object_id, false)) {
                    if ($id) abort(404);
                    continue;
                }
                $this->rows($actor, $tenant)->where('id', $row->id)->whereNull('read_at')->update(['read_at' => now()->toIso8601String()]);
                if (preg_match('/^notification:([1-9][0-9]*)$/D', $row->event_key, $matches)) {
                    DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                        ->where('id', $matches[1])->where('object_id', $row->object_id)->whereIn('object_type', array_keys(self::SOURCES, $row->kind, true))
                        ->whereNull('read_at')->update(['read_at' => DB::connection('clubunio')->raw('clock_timestamp()::timestamp')]);
                }
            }
            return ['unreadCount' => $this->count($actor, $tenant)['unreadCount'], 'read' => true];
        });
    }
}
