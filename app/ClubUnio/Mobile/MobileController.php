<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileController
{
    private function actor(Request $r): Account { return $r->attributes->get('mobile.actor'); }
    private function tenant(Request $r): Tenant { return $r->attributes->get('mobile.tenant'); }
    private function data($data, int $status = 200) { return response()->json(['data' => $data], $status); }

    private function write(Request $r, callable $operation)
    {
        return DB::connection('clubunio')->transaction(function () use ($r, $operation) {
            Tenant::whereKey($this->tenant($r)->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $r->attributes->set('mobile.actor', app(MobileSessions::class)->authenticate($r));
            app(MobileAccess::class)->enter($this->actor($r), $this->tenant($r));

            return $operation();
        });
    }

    public function login(Request $r) { return $this->data(app(MobileSessions::class)->login($r)); }

    public function context(Request $r)
    {
        $actor = $this->actor($r);
        $tenants = $actor->tenants()->get()->filter(fn ($t) => app(MemberAccessService::class)->canEnter($actor, $t));
        $bound = app(\App\ClubUnio\Services\TenantDomainService::class)->resolve($r);
        return $this->data(['account' => ['id' => (int) $actor->id, 'name' => $actor->display_name, 'username' => $actor->username],
            'language' => DB::connection('clubunio')->table('clubora.mobile_preferences')->where('account_id', $actor->id)->value('language') ?? 'de',
            'tenants' => $tenants->filter(fn ($t) => ! $bound || $bound->is($t))->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->values(),
            'capabilities' => ['push' => false, 'uploads' => (bool) config('clubunio-mobile.uploads_enabled'), 'ai' => (bool) config('clubunio-mobile.ai.enabled')]]);
    }

    public function sessions(Request $r)
    {
        return $this->data(DB::connection('clubunio')->table('clubora.mobile_sessions')->where('account_id', $this->actor($r)->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now()->toIso8601String())->orderByDesc('created_at')->limit(30)
            ->get(['id', 'device_name', 'expires_at', 'created_at']));
    }

    public function revoke(Request $r)
    {
        $id = $r->route('session') ?? $r->attributes->get('mobile.session')->id;
        $count = DB::connection('clubunio')->table('clubora.mobile_sessions')->where('account_id', $this->actor($r)->id)->where('id', $id)
            ->update(['revoked_at' => now()->toIso8601String()]);
        abort_unless($count, 404);
        return $this->data(['revoked' => true]);
    }

    public function teams(Request $r)
    {
        $a = $this->actor($r); $t = $this->tenant($r);
        return $this->data(['teams' => app(MobileAccess::class)->teams($a, $t)->orderBy('ts.id')->limit(100)->get(),
            'represented' => app(MemberAccessService::class)->represented($a, $t)->orderBy('id')->limit(50)->get(['id', 'first_name', 'last_name']),
            'can_announce' => app(MobileAccess::class)->admin($a, $t)]);
    }

    public function chats(Request $r)
    {
        return $this->write($r, fn () => $this->data(app(ChatService::class)->conversations($this->actor($r), $this->tenant($r))));
    }

    public function chat(Request $r)
    {
        return $this->write($r, fn () => $this->data(app(ChatService::class)->conversation($this->actor($r), $this->tenant($r), (int) $r->route('chat'))));
    }

    public function contacts(Request $r) { return $this->data(app(MobileAccess::class)->contacts($this->actor($r), $this->tenant($r))); }

    public function direct(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|integer|min:1']);
        return $this->write($r, fn () => $this->data(['id' => app(ChatService::class)->direct($this->actor($r), $this->tenant($r), (int) $d['account_id'])->id, 'other_account_id' => (int) $d['account_id'], 'uploads' => (bool) config('clubunio-mobile.uploads_enabled')]));
    }

    public function messages(Request $r)
    {
        $d = $r->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
        abort_if(! empty($d['after']) && ! empty($d['before']), 422);
        return response()->json(app(ChatService::class)->messages($this->actor($r), $this->tenant($r), (int) $r->route('chat'), (int) ($d['after'] ?? 0), (int) ($d['before'] ?? 0)));
    }

    public function send(Request $r)
    {
        return $this->write($r, function () use ($r) {
            $service = app(ChatService::class); $a = $this->actor($r); $t = $this->tenant($r);
            $row = $service->send($a, $t, (int) $r->route('chat'), $r->all());
            return $this->data($service->messageDto($a, $t, $row), 201);
        });
    }

    public function read(Request $r)
    {
        $d = $r->validate(['message_id' => 'required|integer|min:0']);
        return $this->write($r, function () use ($r, $d) {
            app(ChatService::class)->read($this->actor($r), $this->tenant($r), (int) $r->route('chat'), (int) $d['message_id']);
            return $this->data(['read' => true]);
        });
    }

    public function mute(Request $r)
    {
        $d = $r->validate(['muted' => 'required|boolean']);
        return $this->write($r, function () use ($r, $d) {
            $s = app(ChatService::class); $t = $this->tenant($r); $a = $this->actor($r);
            $chat = $s->chat($a, $t, (int) $r->route('chat'));
            $s->table($t, 'chat_memberships')->where('conversation_id', $chat->id)->where('account_id', $a->id)->update(['muted' => $d['muted']]);
            return $this->data(['muted' => (bool) $d['muted']]);
        });
    }

    public function vote(Request $r)
    {
        $d = $r->validate(['choices' => 'present|array|max:10', 'choices.*' => 'required|uuid|distinct']);
        return $this->write($r, fn () => $this->data(app(ChatService::class)->vote($this->actor($r), $this->tenant($r), (int) $r->route('poll'), $d['choices'])));
    }

    public function events(Request $r)
    {
        $d = $r->validate(['team_season_id' => 'required|integer|min:1']);
        $s = app(TeamPlanningService::class); $a = $this->actor($r); $t = $this->tenant($r);
        abort_unless($s->teams($a, $t)->where('id', $d['team_season_id'])->exists(), 404);
        return $this->data($s->table($t, 'team_events')->where('team_season_id', $d['team_season_id'])->where('ends_at', '>=', now()->toIso8601String())
            ->orderBy('starts_at')->orderBy('id')->limit(50)->get(['id', 'team_season_id', 'title', 'kind', 'starts_at', 'ends_at', 'deadline_at', 'location', 'status', 'revision']));
    }

    public function event(Request $r)
    {
        $s = app(TeamPlanningService::class); $a = $this->actor($r); $t = $this->tenant($r);
        $event = $s->event($a, $t, (int) $r->route('event'));
        $own = app(MemberAccessService::class)->represented($a, $t)->pluck('id')->all();
        $members = $s->roster($t, $event)->whereIn('clubora.members.id', $own)->get(['id', 'first_name', 'last_name']);
        return $this->data(['event' => array_intersect_key((array) $event, array_flip(['id', 'team_season_id', 'title', 'description', 'starts_at', 'ends_at', 'deadline_at', 'location', 'status', 'kind', 'mode', 'capacity', 'revision'])),
            'participations' => $members->map(function ($member) use ($s, $t, $event) {
                $p = $s->table($t, 'team_participations')->where('event_id', $event->id)->where('member_id', $member->id)->first();
                return ['member_id' => (int) $member->id, 'name' => trim($member->first_name.' '.$member->last_name),
                    'status' => $p?->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN'), 'revision' => (int) ($p?->revision ?? 0)];
            }), 'locked' => $s->locked($event)]);
    }

    public function participate(Request $r)
    {
        $d = $r->validate(['member_id' => 'required|integer|min:1', 'revision' => 'required|integer|min:0', 'status' => 'required|in:DABEI,ABWESEND', 'comment' => 'nullable|string|max:1000']);
        return $this->write($r, function () use ($r, $d) {
            // API clients cannot widen the existing trainer workflow through attendance/status fields.
            $service = app(TeamPlanningService::class);
            $event = $service->event($this->actor($r), $this->tenant($r), (int) $r->route('event'));
            if ($service->teams($this->actor($r), $this->tenant($r), true)->where('id', $event->team_season_id)->exists()) {
                $d['attendance'] = $service->table($this->tenant($r), 'team_participations')->where('event_id', $event->id)->where('member_id', $d['member_id'])->value('attendance') ?? 'OFFEN';
            }
            $service->participate($this->actor($r), $this->tenant($r), (int) $r->route('event'), (int) $d['member_id'], $d);
            return $this->data(['saved' => true]);
        });
    }

    public function eventChat(Request $r)
    {
        return $this->write($r, function () use ($r) {
            $a = $this->actor($r); $t = $this->tenant($r);
            $event = app(TeamPlanningService::class)->event($a, $t, (int) $r->route('event'));
            $s = app(ChatService::class); $chat = $s->ensure($t, 'event', (int) $event->team_season_id, (int) $event->id);
            $s->chat($a, $t, (int) $chat->id);
            return $this->data(['id' => (int) $chat->id]);
        });
    }

    public function invoice(Request $r)
    {
        $a = $this->actor($r); $t = $this->tenant($r);
        $invoice = app(MobileAccess::class)->invoice($a, $t, (int) $r->route('invoice'));
        return $this->data(['id' => (int) $invoice->id, 'member_id' => (int) $invoice->member_id,
            'status' => $invoice->status?->name, 'total_cents' => app(\App\ClubUnio\Services\InvoiceTaxService::class)->summary($invoice)['total_cents'],
            'currency' => 'CHF', 'has_pdf' => (bool) $invoice->bexio_invoice_id]);
    }

    public function invoicePdf(Request $r)
    {
        app(MobileAccess::class)->invoice($this->actor($r), $this->tenant($r), (int) $r->route('invoice'));
        return app(\App\ClubUnio\Services\InvoicePdfService::class)->open($this->actor($r), $this->tenant($r), (int) $r->route('invoice'), true);
    }

    public function inbox(Request $r)
    {
        $s = app(ChatService::class); $a = $this->actor($r); $t = $this->tenant($r);
        $result = [];
        foreach ($s->table($t, 'mobile_inbox')->where('account_id', $a->id)->orderByDesc('id')->limit(50)->get() as $row) {
            try {
                if ($row->kind === 'invoice') {
                    app(MobileAccess::class)->invoice($a, $t, $row->object_id);
                } else {
                    $s->chat($a, $t, $row->object_id);
                }
                $result[] = ['id' => (int) $row->id, 'kind' => $row->kind, 'object_id' => (int) $row->object_id, 'read' => (bool) $row->read_at,
                    'title' => $row->kind === 'invoice' ? 'Eine neue Rechnung ist verfügbar' : 'Neue Vereinsankündigung', 'created_at' => $row->created_at];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // Removed permissions never reveal historic content through the inbox.
            }
        }
        return $this->data($result);
    }

    public function preferences(Request $r)
    {
        $d = $r->validate(['language' => 'required|in:de,en,fr,it,pt-PT']);
        $actor = app(MobileSessions::class)->authenticate($r);
        DB::connection('clubunio')->table('clubora.mobile_preferences')->updateOrInsert(['account_id' => $actor->id],
            ['language' => $d['language'], 'updated_at' => now()->toIso8601String()]);
        return $this->data(['language' => $d['language']]);
    }

    public function changes(Request $r)
    {
        $d = $r->validate(['after' => 'required|integer|min:0']);
        return response()->json(app(ChatControls::class)->changes($this->actor($r), $this->tenant($r), (int) $r->route('chat'), (int) $d['after']));
    }

    public function editMessage(Request $r)
    {
        $d = $r->validate(['revision' => 'required|integer|min:1', 'text' => 'required|string|max:4000']);
        return $this->write($r, function () use ($r, $d) {
            $row = app(ChatControls::class)->edit($this->actor($r), $this->tenant($r), (int) $r->route('message'), (int) $d['revision'], $d['text']);
            return $this->data(app(ChatService::class)->messageDto($this->actor($r), $this->tenant($r), $row));
        });
    }

    public function deleteMessage(Request $r)
    {
        $d = $r->validate(['revision' => 'required|integer|min:1']);
        return $this->write($r, function () use ($r, $d) {
            $row = app(ChatControls::class)->edit($this->actor($r), $this->tenant($r), (int) $r->route('message'), (int) $d['revision'], null);
            return $this->data(app(ChatService::class)->messageDto($this->actor($r), $this->tenant($r), $row));
        });
    }

    public function reportMessage(Request $r)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        return $this->write($r, fn () => $this->data(app(ChatControls::class)->report($this->actor($r), $this->tenant($r), (int) $r->route('message'), $d['reason'])));
    }

    public function reports(Request $r)
    {
        return $this->data(app(ChatControls::class)->reports($this->actor($r), $this->tenant($r), (int) $r->route('chat')));
    }

    public function resolveReport(Request $r)
    {
        return $this->write($r, fn () => $this->data(app(ChatControls::class)->resolve($this->actor($r), $this->tenant($r), (int) $r->route('report'))));
    }

    public function announcementPreview(Request $r)
    {
        return $this->data(app(ChatControls::class)->preview($this->actor($r), $this->tenant($r)));
    }

    public function blocks(Request $r)
    {
        $s = app(ChatService::class); $t = $this->tenant($r);
        $ids = $s->table($t, 'chat_blocks')->where('account_id', $this->actor($r)->id)->pluck('other_account_id');
        return $this->data($t->accounts()->whereIn('clubora.accounts.id', $ids)->orderBy('clubora.accounts.id')->limit(100)
            ->get(['clubora.accounts.id', 'clubora.accounts.display_name as name']));
    }

    public function block(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|integer|min:1', 'blocked' => 'required|boolean']);
        return $this->write($r, function () use ($r, $d) {
            $s = app(ChatService::class); $a = $this->actor($r); $t = $this->tenant($r);
            $query = $s->table($t, 'chat_blocks')->where('account_id', $a->id)->where('other_account_id', $d['account_id']);
            if ($d['blocked']) {
                $other = Account::findOrFail($d['account_id']);
                abort_unless((clone $query)->exists() || app(MobileAccess::class)->canContact($a, $other, $t), 404);
                $s->table($t, 'chat_blocks')->insertOrIgnore(['tenant_id' => $t->id, 'account_id' => $a->id, 'other_account_id' => $other->id, 'created_at' => now()->toIso8601String()]);
            } else { $query->delete(); }
            return $this->data(['blocked' => (bool) $d['blocked']]);
        });
    }

    public function inboxRead(Request $r)
    {
        return $this->write($r, function () use ($r) {
            $s = app(ChatService::class); $a = $this->actor($r); $t = $this->tenant($r);
            $q = $s->table($t, 'mobile_inbox')->where('account_id', $a->id)->where('id', $r->route('notice'));
            $row = (clone $q)->firstOrFail();
            if ($row->kind === 'invoice') { app(MobileAccess::class)->invoice($a, $t, $row->object_id); }
            else { $s->chat($a, $t, (int) $row->object_id); }
            $q->whereNull('read_at')->update(['read_at' => now()->toIso8601String()]);
            return $this->data(['read' => true]);
        });
    }

    public function uploadAttachment(Request $r)
    {
        $d = $r->validate(['client_id' => 'required|uuid', 'file' => 'required|file|max:5120']);
        return $this->write($r, fn () => $this->data(app(ChatAttachments::class)->stage($this->actor($r), $this->tenant($r), (int) $r->route('chat'), $d['client_id'], $r->file('file')), 201));
    }

    public function scanAttachment(Request $r)
    {
        $s = app(ChatAttachments::class); $id = $r->route('attachment');
        $row = $s->owned($this->actor($r), $this->tenant($r), $id);
        if ($row->state !== 'quarantine') { return $this->data(['id' => $id, 'state' => $row->state]); }
        // Scanner I/O deliberately outside SQL/tenant locks. Fresh authorization follows.
        $verdict = $s->scanBytes($row);
        return $this->write($r, fn () => $this->data($s->finishScan($this->actor($r), $this->tenant($r), $id, $verdict)));
    }

    public function cancelAttachment(Request $r)
    {
        return $this->write($r, function () use ($r) {
            $row = app(ChatAttachments::class)->owned($this->actor($r), $this->tenant($r), $r->route('attachment'));
            abort_if($row->message_id, 409);
            app(ChatService::class)->table($this->tenant($r), 'chat_attachments')->where('id', $row->id)
                ->update(['state' => 'removed', 'bytes_encrypted' => null]);
            return $this->data(['removed' => true]);
        });
    }

    public function downloadAttachment(Request $r)
    {
        return app(ChatAttachments::class)->download($this->actor($r), $this->tenant($r), $r->route('attachment'));
    }

    public function ai(Request $r)
    {
        return $this->data(app(MobileAiService::class)->execute($this->actor($r), $this->tenant($r), $r->all()));
    }
}
