<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Mobile\{ChatAccess, ChatAttachments, ChatControls, ChatPreferences, ChatService, MobileAiService};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ActiveTenant, TeamPlanningService};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Session adapter, not a second chat store or an internal mobile login. */
final class WebChatController
{
    private const READS = ['list', 'conversation', 'messages', 'changes', 'contacts', 'events', 'preview', 'reports', 'reactions', 'preferences', 'blocks', 'download'];
    private const WRITES = ['direct', 'send', 'read', 'mute', 'vote', 'react', 'edit', 'delete', 'report', 'resolve', 'preferences-save', 'block', 'upload', 'scan', 'attachment-remove', 'ai', 'event-chat'];

    public function __invoke(Request $request, string $operation)
    {
        abort_unless(in_array($operation, $request->isMethod('GET') ? self::READS : self::WRITES, true), 404);
        $context = $request->validate(['context' => 'required|array:tenant,revision,host', 'context.tenant' => 'required|integer|min:1', 'context.revision' => 'required|uuid', 'context.host' => 'sometimes|required|string|max:253'])['context'];
        $context['tenant'] = (int)$context['tenant'];
        app(ActiveTenant::class)->assertSnapshot($request, $context);
        $actor = $request->user('clubunio')?->fresh();
        $tenant = Filament::getTenant();
        abort_unless($actor && $tenant && !$actor->api_only && $actor->canAccessTenant($tenant), 403);
        app(ChatAccess::class)->authorize($actor, $tenant, 'view');
        if (!$request->isMethod('GET')) {
            abort_if($request->attributes->has('clubunio_impersonation'), 403);
            if (!in_array($operation, ['read', 'resolve'], true)) app(ChatAccess::class)->authorize($actor, $tenant, 'send');
        }

        // Network/scanner work must never hold the shared tenant write lock.
        $verdict = null;
        if ($operation === 'scan') {
            $data = $request->validate(['attachment_id' => 'required|uuid']);
            $row = app(ChatAttachments::class)->owned($actor, $tenant, $data['attachment_id']);
            if ($row->state === 'quarantine') $verdict = app(ChatAttachments::class)->scanBytes($row);
        }
        if ($operation === 'ai') {
            return response()->json(['data' => app(MobileAiService::class)->execute($actor, $tenant, $request->all())])->header('Cache-Control', 'private, no-store');
        }
        $run = function () use ($request, $operation, $context, $tenant, $verdict) {
            app(ActiveTenant::class)->assertSnapshot($request, $context);
            $actor = $request->user('clubunio')->fresh();
            abort_unless($actor && !$actor->api_only && $actor->canAccessTenant($tenant), 403);
            app(ChatAccess::class)->authorize($actor, $tenant, 'view');
            if (!$request->isMethod('GET') && !in_array($operation, ['read', 'resolve'], true)) app(ChatAccess::class)->authorize($actor, $tenant, 'send');
            return app(ChatAccess::class)->duringRead($actor, $tenant,
                fn () => $this->dispatch($request, $actor, $tenant, $operation, $verdict));
        };
        // Conversation reads can provision membership. Serialize them like mobile;
        // downloads and deltas stay on the bounded read path.
        $locks = !$request->isMethod('GET') || in_array($operation, ['list', 'conversation'], true);
        $result = $locks ? DB::connection('clubunio')->transaction(function () use ($tenant, $run) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            return $run();
        }) : $run();
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) return $result;
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function dispatch(Request $r, Account $a, Tenant $t, string $op, ?array $verdict): mixed
    {
        $s = app(ChatService::class); $c = app(ChatControls::class); $p = app(ChatAccess::class);
        $id = function (string $key) use ($r): int { return (int)$r->validate([$key => 'required|integer|min:1'])[$key]; };
        $revision = fn (): int => (int)$r->validate(['revision' => 'required|integer|min:1'])['revision'];
        $uuid = fn (): string => $r->validate(['attachment_id' => 'required|uuid'])['attachment_id'];
        $data = fn ($value) => ['data' => $value];
        switch ($op) {
            case 'list':
                return $data(['conversations' => $s->conversations($a, $t), 'capabilities' => $this->capabilities($a, $t)]);
            case 'conversation': return $data($s->conversation($a, $t, $id('chat_id')));
            case 'messages':
                $d = $r->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
                abort_if(!empty($d['after']) && !empty($d['before']), 422);
                return $s->messages($a, $t, $id('chat_id'), (int)($d['after'] ?? 0), (int)($d['before'] ?? 0));
            case 'changes':
                $d = $r->validate(['after' => 'required|integer|min:0']);
                return $c->changes($a, $t, $id('chat_id'), (int)$d['after']);
            case 'contacts':
                $d = $r->validate(['q' => 'nullable|string|max:120']);
                return $data($p->contacts($a, $t, $d['q'] ?? ''));
            case 'direct': return $data(['id' => (int)$s->direct($a, $t, $id('account_id'))->id]);
            case 'send': return $data($s->messageDto($a, $t, $s->send($a, $t, $id('chat_id'), $r->all())));
            case 'read':
                $d = $r->validate(['message_id' => 'required|integer|min:0']);
                $s->read($a, $t, $id('chat_id'), (int)$d['message_id']);
                return $data(['read' => true]);
            case 'mute':
                $d = $r->validate(['muted' => 'required|boolean']); $chat = $s->chat($a, $t, $id('chat_id'));
                $s->table($t, 'chat_memberships')->where('conversation_id', $chat->id)->where('account_id', $a->id)->update(['muted' => $d['muted']]);
                return $data(['muted' => (bool)$d['muted']]);
            case 'vote':
                $d = $r->validate(['choices' => 'present|array|max:10', 'choices.*' => 'required|uuid|distinct']);
                return $data($s->vote($a, $t, $id('poll_id'), $d['choices']));
            case 'react':
                $d = $r->validate(['emoji' => 'present|nullable|string|max:16']);
                return $data($s->messageDto($a, $t, $c->react($a, $t, $id('message_id'), $revision(), $d['emoji'])));
            case 'edit':
                $d = $r->validate(['text' => 'required|string|max:4000']);
                return $data($s->messageDto($a, $t, $c->edit($a, $t, $id('message_id'), $revision(), $d['text'])));
            case 'delete': return $data($s->messageDto($a, $t, $c->edit($a, $t, $id('message_id'), $revision(), null)));
            case 'report':
                $d = $r->validate(['reason' => 'required|string|max:1000']);
                return $data($c->report($a, $t, $id('message_id'), $d['reason']));
            case 'reports': return $data($c->reports($a, $t, $id('chat_id')));
            case 'resolve': return $data($c->resolve($a, $t, $id('report_id')));
            case 'reactions':
                // Controls accepts only its narrow query contract, not workspace context.
                $query = Request::create('/', 'GET', $r->only(['after', 'limit']));
                return $c->reactionParticipants($query, $a, $t, $id('message_id'));
            case 'preview': return $data($c->preview($a, $t));
            case 'preferences': return $data(app(ChatPreferences::class)->get($a, $t));
            case 'preferences-save': return $data(app(ChatPreferences::class)->save($a, $t, $r->all()));
            case 'events':
                $team = $id('team_season_id');
                abort_unless($p->teams($a, $t)->where('ts.id', $team)->exists(), 404);
                return $data(app(TeamPlanningService::class)->table($t, 'team_events')->where('team_season_id', $team)
                    ->where('ends_at', '>=', now()->toIso8601String())->orderBy('starts_at')->orderBy('id')->limit(50)
                    ->get(['id', 'title', 'starts_at', 'ends_at', 'location', 'status']));
            case 'event-chat':
                $event = $p->event($a, $t, $id('event_id'));
                return $data(['id' => (int)$s->ensure($t, 'event', (int)$event->team_season_id, (int)$event->id)->id]);
            case 'blocks':
                $ids = $s->table($t, 'chat_blocks')->where('account_id', $a->id)->pluck('other_account_id');
                return $data($t->accounts()->whereIn('clubora.accounts.id', $ids)->orderBy('clubora.accounts.id')->limit(100)->get(['clubora.accounts.id', 'display_name as name']));
            case 'block':
                $d = $r->validate(['blocked' => 'required|boolean']); $other = $id('account_id');
                $q = $s->table($t, 'chat_blocks')->where('account_id', $a->id)->where('other_account_id', $other);
                if ($d['blocked']) {
                    abort_unless((clone $q)->exists() || $p->canContact($a, Account::findOrFail($other), $t), 404);
                    $q->insertOrIgnore(['tenant_id' => $t->id, 'account_id' => $a->id, 'other_account_id' => $other, 'created_at' => now()->toIso8601String()]);
                } else $q->delete();
                return $data(['blocked' => (bool)$d['blocked']]);
            case 'upload':
                $d = $r->validate(['client_id' => 'required|uuid', 'file' => 'required|file|max:5120']);
                return $data(app(ChatAttachments::class)->stage($a, $t, $id('chat_id'), $d['client_id'], $r->file('file')));
            case 'scan':
                $attachment = app(ChatAttachments::class)->owned($a, $t, $uuid());
                return $data($attachment->state === 'quarantine' ? app(ChatAttachments::class)->finishScan($a, $t, $attachment->id, $verdict ?? []) : ['id' => $attachment->id, 'state' => $attachment->state]);
            case 'attachment-remove':
                $row = app(ChatAttachments::class)->owned($a, $t, $uuid()); abort_if($row->message_id, 409);
                $s->table($t, 'chat_attachments')->where('id', $row->id)->update(['state' => 'removed', 'bytes_encrypted' => null]);
                return $data(['removed' => true]);
            case 'download': return app(ChatAttachments::class)->download($a, $t, $uuid());
        }
        abort(404);
    }

    public function capabilities(Account $actor, Tenant $tenant): array
    {
        $result = [];
        foreach (['view', 'send', 'upload', 'download', 'poll', 'announce', 'moderate', 'groups'] as $function) {
            $result[$function] = app(ChatAccess::class)->allows($actor, $tenant, $function);
        }
        $result['uploads_enabled'] = (bool)config('clubunio-mobile.uploads_enabled');
        $result['ai_enabled'] = (bool)config('clubunio-mobile.ai.enabled');
        $result['push_enabled'] = (bool)config('clubunio-mobile-push.enabled');
        return $result;
    }
}
