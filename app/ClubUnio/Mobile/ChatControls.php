<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\Crypt;

final class ChatControls
{
    private function service(): ChatService { return app(ChatService::class); }

    public function moderator(Account $actor, Tenant $tenant, object $chat): bool
    {
        if ($chat->kind === 'direct') { return false; }
        $access = app(MobileAccess::class);
        return $access->admin($actor, $tenant) || ($chat->team_season_id
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
        $s = $this->service(); $row = $this->visible($actor, $tenant, $id);
        $chat = $s->chat($actor, $tenant, (int) $row->conversation_id);
        $own = (int) $row->account_id === (int) $actor->id;
        abort_unless($own || ($text === null && $this->moderator($actor, $tenant, $chat)), 403);
        abort_if($row->deleted_at || (int) $row->revision !== $revision, 409);
        if ($text !== null) {
            abort_unless($own && in_array($row->kind, ['text', 'announcement'], true), 403);
            abort_unless(\Carbon\CarbonImmutable::parse($row->created_at)->gte(now()->subMinutes(30)), 409);
            abort_if(trim($text) === '', 422);
            if ($row->kind === 'announcement') { abort_unless(app(MobileAccess::class)->admin($actor, $tenant), 403); }
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
        }
        $fresh = $s->table($tenant, 'chat_messages')->where('id', $id)->first();
        $this->record($actor, $tenant, $fresh, $text === null ? 'delete' : 'edit');
        return $fresh;
    }

    public function changes(Account $actor, Tenant $tenant, int $chatId, int $after): array
    {
        $s = $this->service(); $s->chat($actor, $tenant, $chatId);
        $joined = $s->table($tenant, 'chat_memberships')->where('conversation_id', $chatId)->where('account_id', $actor->id)->value('joined_after');
        $rows = $s->table($tenant, 'chat_changes')->where('conversation_id', $chatId)->where('id', '>', $after)
            ->where('message_id', '>', $joined)->orderBy('id')->limit(51)->get();
        $more = $rows->count() > 50; $rows = $rows->take(50);
        return ['data' => $rows->map(function ($change) use ($s, $actor, $tenant) {
            $row = $s->table($tenant, 'chat_messages')->where('id', $change->message_id)->firstOrFail();
            return ['cursor' => (int) $change->id, 'message' => $s->messageDto($actor, $tenant, $row)];
        })->values()->all(), 'meta' => ['has_more' => $more, 'cursor' => (int) ($rows->max('id') ?? $after)]];
    }

    public function preview(Account $actor, Tenant $tenant): array
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless(app(MobileAccess::class)->admin($actor, $tenant), 403);
        $people = $tenant->accounts()->where('active', true)->where('api_only', false)->orderBy('clubora.accounts.id')->get()
            ->filter(fn ($a) => app(MemberAccessService::class)->canEnter($a, $tenant))
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->display_name])->values()->all();
        return ['count' => count($people), 'recipients' => $people,
            'fingerprint' => hash('sha256', json_encode(array_column($people, 'id'), JSON_THROW_ON_ERROR))];
    }

    public function report(Account $actor, Tenant $tenant, int $message, string $reason): array
    {
        $row = $this->visible($actor, $tenant, $message);
        $chat = $this->service()->chat($actor, $tenant, (int) $row->conversation_id);
        abort_unless($chat->kind !== 'direct', 403); // No private moderation grant without a separate policy.
        abort_if($row->deleted_at || trim($reason) === '', 422);
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
        $s = $this->service(); $report = $s->table($tenant, 'chat_reports')->where('id', $id)->firstOrFail();
        $chat = $s->chat($actor, $tenant, (int) $report->conversation_id);
        abort_unless($this->moderator($actor, $tenant, $chat), 403);
        $this->visible($actor, $tenant, (int) $report->message_id);
        $s->table($tenant, 'chat_reports')->where('id', $id)->where('state', 'open')->update(['state' => 'resolved',
            'reason_encrypted' => Crypt::encryptString(''), 'resolved_by' => $actor->id, 'resolved_at' => now()->toIso8601String()]);
        return ['resolved' => true];
    }
}
