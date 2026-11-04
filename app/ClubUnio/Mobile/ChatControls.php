<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

final class ChatControls
{
    private function service(): ChatService { return app(ChatService::class); }

    /** Capability within an already-authorized conversation, never a visibility grant. */
    public function moderator(Account $actor, Tenant $tenant, object $chat): bool
    {
        if ((int) $chat->tenant_id !== (int) $tenant->id || $chat->kind === 'direct'
            || !app(ChatAccess::class)->allows($actor, $tenant, 'moderate')) { return false; }
        $access = app(ChatAccess::class);
        return ($chat->kind === 'announcements' && $access->isProfile($actor, $tenant))
            || $access->admin($actor, $tenant) || ($chat->team_season_id
            && $access->teams($actor, $tenant, true)->where('ts.id', $chat->team_season_id)->exists());
    }

    public function record(Account $actor, Tenant $tenant, object $row, string $action): void
    {
        $this->service()->table($tenant, 'chat_changes')->insert(['tenant_id' => $tenant->id,
            'conversation_id' => $row->conversation_id, 'message_id' => $row->id, 'actor_id' => $actor->id,
            'action' => $action, 'revision' => $row->revision, 'created_at' => now()->toIso8601String()]);
    }

    public function visible(Account $actor, Tenant $tenant, int $id): object
    {
        $s = $this->service();
        $row = $s->table($tenant, 'chat_messages')->where('id', $id)->firstOrFail();
        $s->chat($actor, $tenant, (int) $row->conversation_id);
        $joined = $s->table($tenant, 'chat_memberships')->where('conversation_id', $row->conversation_id)->where('account_id', $actor->id)->value('joined_after');
        abort_unless($row->id > $joined, 404);
        return $row;
    }

    public function edit(Account $actor, Tenant $tenant, int $id, int $revision, ?string $text): object
    {
        app(ChatAccess::class)->authorize($actor, $tenant, 'send');
        $s = $this->service(); $row = $this->visible($actor, $tenant, $id);
        $chat = $s->chat($actor, $tenant, (int) $row->conversation_id);
        $own = (int) $row->account_id === (int) $actor->id;
        abort_unless($own || ($text === null && $this->moderator($actor, $tenant, $chat)), 403);
        if ($text === null && $own && $row->kind === 'announcement'
            && !$this->moderator($actor, $tenant, $chat)) {
            app(ChatAccess::class)->authorize($actor, $tenant, 'announce');
        }
        abort_if($row->deleted_at || (int) $row->revision !== $revision, 409);
        if ($text !== null) {
            abort_unless($own && in_array($row->kind, ['text', 'announcement'], true), 403);
            abort_unless(\Carbon\CarbonImmutable::parse($row->created_at)->gte(now()->subMinutes(30)), 409);
            abort_if(trim($text) === '' || mb_strlen($text) > 4000, 422);
            if ($row->kind === 'announcement') { app(ChatAccess::class)->authorize($actor, $tenant, 'announce'); }
        }
        $s->table($tenant, 'chat_messages')->where('id', $id)->update(['revision' => $revision + 1,
            'body_encrypted' => Crypt::encryptString($text ?? ''), 'deleted_at' => $text === null ? now()->toIso8601String() : null]);
        // Derived content must not remain recoverable through the translation cache.
        $s->table($tenant, 'mobile_ai_work')->where('message_id', $id)->update(['result_encrypted' => null, 'state' => 'invalidated']);
        if ($text === null && $row->kind === 'poll') {
            $s->table($tenant, 'chat_polls')->where('id', $row->object_id)->update(['question' => '', 'options' => '[]']);
            $s->table($tenant, 'chat_votes')->where('poll_id', $row->object_id)->delete();
        }
        if ($text === null) {
            $s->table($tenant, 'chat_attachments')->where('message_id', $id)->update(['state' => 'removed', 'bytes_encrypted' => null]);
            $s->table($tenant, 'chat_reactions')->where('message_id', $id)->delete();
        }
        $fresh = $s->table($tenant, 'chat_messages')->where('id', $id)->first();
        $this->record($actor, $tenant, $fresh, $text === null ? 'delete' : 'edit');
        return $fresh;
    }

    public function react(Account $actor, Tenant $tenant, int $id, int $revision, ?string $emoji): object
    {
        app(ChatAccess::class)->authorize($actor, $tenant, 'send');
        $row = $this->visible($actor, $tenant, $id);
        abort_if($row->deleted_at || (int) $row->revision !== $revision, 409);
        abort_unless($emoji === null || in_array($emoji, ['👍', '❤️', '😂', '😮', '😢', '🙏'], true), 422);
        $query = $this->service()->table($tenant, 'chat_reactions')->where('message_id', $id)->where('account_id', $actor->id);
        $old = (clone $query)->value('emoji');
        if ($old === $emoji) return $row;
        if ($emoji === null) $query->delete();
        else $query->updateOrInsert(['tenant_id' => $tenant->id, 'message_id' => $id, 'account_id' => $actor->id],
            ['emoji' => $emoji, 'updated_at' => now()->toIso8601String()]);
        $this->record($actor, $tenant, $row, 'reaction');
        return $row;
    }

    /** On-demand names only; message aggregates remain bounded and contain no participant list. */
    public function reactionParticipants(\Illuminate\Http\Request $request, Account $actor, Tenant $tenant, int $id): array
    {
        $row = $this->visible($actor, $tenant, $id);
        abort_if($row->deleted_at, 404);
        if (array_diff(array_keys($request->query()), ['after', 'limit'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['query' => 'Nicht erlaubter Reaktionsfilter.']);
        }
        $data = \Illuminate\Support\Facades\Validator::make($request->query(),
            ['after' => 'sometimes|required|integer|min:0', 'limit' => 'sometimes|required|integer|min:1|max:50'])->validate();
        $after = (int)($data['after'] ?? 0); $limit = (int)($data['limit'] ?? 30);
        $rows = \Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.chat_reactions as reaction')
            ->join('clubora.accounts as account','account.id','=','reaction.account_id')
            ->where('reaction.tenant_id',$tenant->id)->where('reaction.message_id',$id)->where('reaction.account_id','>',$after)
            ->orderBy('reaction.account_id')->limit($limit + 1)->get(['reaction.account_id','reaction.emoji','account.display_name']);
        $more = $rows->count() > $limit; $page = $rows->take($limit);
        return ['data' => $page->map(fn($reaction) => ['account_id'=>(int)$reaction->account_id,'name'=>$reaction->display_name,
            'emoji'=>$reaction->emoji,'mine'=>(int)$reaction->account_id===(int)$actor->id])->values()->all(),
            'meta' => ['has_more'=>$more,'next_after'=>(int)($page->last()?->account_id ?? $after)]];
    }

    public function changes(Account $actor, Tenant $tenant, int $chatId, int $after): array
    {
        $s = $this->service(); $chat = $s->chat($actor, $tenant, $chatId);
        $joined = (int) $s->table($tenant, 'chat_memberships')->where('conversation_id', $chatId)->where('account_id', $actor->id)->value('joined_after');
        // Capture before selecting DTOs. Later commits remain replayable next time;
        // never advance to a highwater read after materializing the message state.
        $upper = (int) ($s->table($tenant, 'chat_changes')->where('conversation_id', $chatId)->max('id') ?? 0);
        $meta = $s->deltaMeta($actor, $tenant, $chat, $joined) + ['reset_required' => $after > $upper];
        if ($after > $upper) return ['data' => [], 'meta' => $meta + ['has_more' => false, 'cursor' => $upper]];
        $rows = $s->table($tenant, 'chat_changes')->where('conversation_id', $chatId)->where('id', '>', $after)->where('id', '<=', $upper)
            ->where('message_id', '>', $joined)->orderBy('id')->limit(51)->get();
        $more = $rows->count() > 50; $rows = $rows->take(50);
        $cursor = $more ? (int) $rows->last()->id : $upper;
        if ($rows->isEmpty()) return ['data' => [], 'meta' => $meta + ['has_more' => false, 'cursor' => $cursor]];
        $messages = $s->table($tenant, 'chat_messages')->where('conversation_id', $chatId)->whereIn('id', $rows->pluck('message_id'))->get();
        $dtos = $s->messageDtos($actor, $tenant, $messages);
        return ['data' => $rows->map(function ($change) use ($dtos) {
            abort_unless(isset($dtos[(int) $change->message_id]), 404);
            return ['cursor' => (int) $change->id, 'message' => $dtos[(int) $change->message_id]];
        })->values()->all(), 'meta' => $meta + ['has_more' => $more, 'cursor' => $cursor]];
    }

    public function preview(Account $actor, Tenant $tenant): array
    {
        app(ChatAccess::class)->enter($actor, $tenant);
        app(ChatAccess::class)->authorize($actor, $tenant, 'announce');
        $people = $tenant->accounts()->where('active', true)->where('api_only', false)->orderBy('clubora.accounts.id')->get()
            ->filter(fn ($a) => app(ChatAccess::class)->allows($a, $tenant, 'view'))
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->display_name])->values()->all();
        return ['count' => count($people), 'recipients' => $people,
            'fingerprint' => hash('sha256', json_encode(array_column($people, 'id'), JSON_THROW_ON_ERROR))];
    }

    public function report(Account $actor, Tenant $tenant, int $message, string $reason): array
    {
        app(ChatAccess::class)->authorize($actor, $tenant, 'send');
        $row = $this->visible($actor, $tenant, $message);
        $chat = $this->service()->chat($actor, $tenant, (int) $row->conversation_id);
        abort_unless($chat->kind !== 'direct', 403); // No private moderation grant without a separate policy.
        abort_if($row->deleted_at || trim($reason) === '' || mb_strlen($reason) > 1000, 422);
        $s = $this->service();
        $s->table($tenant, 'chat_reports')->insertOrIgnore(['tenant_id' => $tenant->id, 'conversation_id' => $row->conversation_id,
            'message_id' => $row->id, 'account_id' => $actor->id, 'reason_encrypted' => Crypt::encryptString($reason),
            'message_revision' => $row->revision, 'state' => 'open', 'created_at' => now()->toIso8601String()]);
        return ['reported' => true];
    }

    public function reports(Account $actor, Tenant $tenant, int $chatId): array
    {
        $s = $this->service(); $chat = $s->chat($actor, $tenant, $chatId);
        abort_unless($this->moderator($actor, $tenant, $chat), 403);
        $joined = $s->table($tenant, 'chat_memberships')->where('conversation_id', $chatId)->where('account_id', $actor->id)->value('joined_after');
        return $s->table($tenant, 'chat_reports')->where('conversation_id', $chatId)->where('message_id', '>', $joined)->where('state', 'open')->orderBy('id')->limit(50)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'message_id' => (int) $r->message_id,
                'reason' => Crypt::decryptString($r->reason_encrypted), 'revision' => (int) $r->message_revision,
                'message' => $s->messageDto($actor, $tenant, $this->visible($actor, $tenant, (int) $r->message_id))])->all();
    }

    public function resolve(Account $actor, Tenant $tenant, int $id): array
    {
        app(ChatAccess::class)->authorize($actor, $tenant, 'moderate');
        $s = $this->service(); $report = $s->table($tenant, 'chat_reports')->where('id', $id)->firstOrFail();
        $chat = $s->chat($actor, $tenant, (int) $report->conversation_id);
        abort_unless($this->moderator($actor, $tenant, $chat), 403);
        $this->visible($actor, $tenant, (int) $report->message_id);
        $s->table($tenant, 'chat_reports')->where('id', $id)->where('state', 'open')->update(['state' => 'resolved',
            'reason_encrypted' => Crypt::encryptString(''), 'resolved_by' => $actor->id, 'resolved_at' => now()->toIso8601String()]);
        return ['resolved' => true];
    }
}
