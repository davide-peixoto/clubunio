<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ChatService
{
    public function table(Tenant $tenant, string $name)
    {
        return DB::connection('clubunio')->table('clubora.'.$name)->where('tenant_id', $tenant->id);
    }

    public function ensure(Tenant $tenant, string $kind, ?int $team = null, ?int $event = null, ?string $key = null): object
    {
        $key ??= $kind.':'.($event ?? $team ?? 'club');
        $this->table($tenant, 'chat_conversations')->insertOrIgnore(['tenant_id' => $tenant->id,
            'kind' => $kind, 'scope_key' => $key, 'team_season_id' => $team, 'event_id' => $event, 'created_at' => now()->toIso8601String()]);

        return $this->table($tenant, 'chat_conversations')->where('scope_key', $key)->firstOrFail();
    }

    public function provisionTeam(Tenant $tenant, int $id): void
    {
        abort_unless($this->table($tenant, 'team_seasons')->where('id', $id)->exists(), 404);
        $this->ensure($tenant, 'team', $id);
        $this->ensure($tenant, 'staff', $id);
    }

    private function membership(Account $actor, Tenant $tenant, object $chat): object
    {
        $query = $this->table($tenant, 'chat_memberships')->where('conversation_id', $chat->id)->where('account_id', $actor->id);
        $row = (clone $query)->first();
        if ($row && !$row->left_at) return $row;
        if (! $row || $row->left_at) {
            $latest = (int) ($this->table($tenant, 'chat_messages')->where('conversation_id', $chat->id)->max('id') ?? 0);
            $query->updateOrInsert(['conversation_id' => $chat->id, 'account_id' => $actor->id],
                ['tenant_id' => $tenant->id, 'joined_after' => $latest, 'left_at' => null, 'read_message_id' => $latest, 'muted' => false]);
        }

        return $query->first();
    }

    public function allowed(Account $actor, Tenant $tenant, object $chat): bool
    {
        $access = app(MobileAccess::class);
        try {
            $access->enter($actor, $tenant);
            if ((int) $chat->tenant_id !== (int) $tenant->id) {
                return false;
            }
            if ($chat->kind === 'announcements') {
                return true;
            }
            if ($chat->kind === 'team' || $chat->kind === 'staff') {
                return $access->teams($actor, $tenant, $chat->kind === 'staff')->where('ts.id', $chat->team_season_id)->exists();
            }
            if ($chat->kind === 'event') {
                app(TeamPlanningService::class)->event($actor, $tenant, $chat->event_id);

                return true;
            }
            if ($chat->kind !== 'direct') {
                return false;
            }
            $members = $this->table($tenant, 'chat_memberships')->where('conversation_id', $chat->id)->whereNull('left_at')->pluck('account_id')->all();
            if (count($members) !== 2 || ! in_array($actor->id, $members)) {
                return false;
            }
            $other = (int) current(array_diff($members, [$actor->id]));

            $candidate = Account::whereKey($other)->first();
            return $candidate && $access->canContact($actor, $candidate, $tenant);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return false;
        }
    }

    public function chat(Account $actor, Tenant $tenant, int $id, bool $writing = false): object
    {
        $chat = $this->table($tenant, 'chat_conversations')->where('id', $id)->first();
        abort_unless($chat && $this->allowed($actor, $tenant, $chat), 404);
        if ($writing && $chat->kind === 'announcements') {
            abort_unless(app(MobileAccess::class)->admin($actor, $tenant), 403);
        }
        $this->membership($actor, $tenant, $chat);

        return $chat;
    }

    public function conversations(Account $actor, Tenant $tenant): array
    {
        $access = app(MobileAccess::class);
        $access->enter($actor, $tenant);
        // Fresh policy builders exist only for this projection, never across requests.
        $teamQuery = $access->teams($actor, $tenant);
        $staffQuery = $access->teams($actor, $tenant, true);
        $teams = (clone $teamQuery)->orderBy('ts.id')->limit(100)->get();
        $staffTeams = (clone $staffQuery)->orderBy('ts.id')->limit(100)->get();
        $needed = ['announcements:club' => ['kind' => 'announcements', 'team_season_id' => null]];
        foreach ($teams as $team) $needed['team:'.$team->id] = ['kind' => 'team', 'team_season_id' => $team->id];
        foreach ($staffTeams as $team) $needed['staff:'.$team->id] = ['kind' => 'staff', 'team_season_id' => $team->id];
        $existing = $this->table($tenant, 'chat_conversations')->whereIn('scope_key', array_keys($needed))->pluck('scope_key')->all();
        $missing = array_diff_key($needed, array_fill_keys($existing, true));
        if ($missing) {
            $insert = [];
            foreach ($missing as $key => $scope) $insert[] = $scope + ['tenant_id' => $tenant->id, 'scope_key' => $key, 'created_at' => now()->toIso8601String()];
            $this->table($tenant, 'chat_conversations')->insertOrIgnore($insert);
        }
        $rows = $this->table($tenant, 'chat_conversations')->where(function ($q) use ($actor, $tenant, $teams) {
            $q->where('kind', 'announcements')->orWhereIn('team_season_id', $teams->pluck('id'))
                ->orWhereIn('id', $this->table($tenant, 'chat_memberships')->where('account_id', $actor->id)->select('conversation_id'));
        })->orderByDesc('id')->limit(200)->get();
        // Include candidate scopes beyond the provisioning limit, preserving old membership access.
        $candidateTeams = $rows->pluck('team_season_id')->filter()->unique();
        $permittedTeams = (clone $teamQuery)->whereIn('ts.id', $candidateTeams)->pluck('ts.id')->map(fn ($id) => (int) $id)->all();
        $permittedStaff = (clone $staffQuery)->whereIn('ts.id', $candidateTeams)->pluck('ts.id')->map(fn ($id) => (int) $id)->all();
        $admin = $access->admin($actor, $tenant);
        $accepted = collect(); $denied = [];
        foreach ($rows as $chat) {
            $allowed = match ($chat->kind) {
                'announcements' => true,
                'team' => in_array((int) $chat->team_season_id, $permittedTeams, true),
                'staff' => in_array((int) $chat->team_season_id, $permittedStaff, true),
                default => $this->allowed($actor, $tenant, $chat), // Direct/event policies stay fully fresh.
            };
            if ($allowed) $accepted->push($chat); else $denied[] = $chat->id;
        }
        if ($denied) $this->table($tenant, 'chat_memberships')->whereIn('conversation_id', $denied)->where('account_id', $actor->id)->update(['left_at' => now()->toIso8601String()]);
        if ($accepted->isEmpty()) return [];
        $memberships = $this->table($tenant, 'chat_memberships')->whereIn('conversation_id', $accepted->pluck('id'))->where('account_id', $actor->id)->get()->keyBy('conversation_id');
        $join = $accepted->filter(fn ($chat) => !isset($memberships[$chat->id]) || $memberships[$chat->id]->left_at);
        if ($join->isNotEmpty()) {
            $latest = $this->table($tenant, 'chat_messages')->whereIn('conversation_id', $join->pluck('id'))->groupBy('conversation_id')->selectRaw('conversation_id,max(id) as latest')->pluck('latest', 'conversation_id');
            $records = $join->map(fn ($chat) => ['tenant_id' => $tenant->id, 'conversation_id' => $chat->id, 'account_id' => $actor->id,
                'joined_after' => (int) ($latest[$chat->id] ?? 0), 'left_at' => null, 'read_message_id' => (int) ($latest[$chat->id] ?? 0), 'muted' => false])->all();
            // A competing request may have joined/read since our first SELECT.
            // Preserve its active row; only a still-left membership may rejoin.
            $updates = [];
            $target = Str::afterLast(DB::connection('clubunio')->getQueryGrammar()->wrapTable('clubora.chat_memberships'), '.');
            foreach (['joined_after','left_at','read_message_id','muted'] as $column) {
                $updates[$column] = new \Illuminate\Database\Query\Expression('CASE WHEN '.$target.'."left_at" IS NOT NULL THEN "excluded"."'.$column.'" ELSE '.$target.'."'.$column.'" END');
            }
            $this->table($tenant, 'chat_memberships')->upsert($records, ['conversation_id','account_id'], $updates);
            $memberships = $this->table($tenant, 'chat_memberships')->whereIn('conversation_id', $accepted->pluck('id'))->where('account_id', $actor->id)->get()->keyBy('conversation_id');
        }
        $unread = DB::connection('clubunio')->table('clubora.chat_messages as m')->join('clubora.chat_memberships as membership', function ($join) use ($actor) {
            $join->on('membership.tenant_id', '=', 'm.tenant_id')->on('membership.conversation_id', '=', 'm.conversation_id')->where('membership.account_id', $actor->id);
        })->where('m.tenant_id', $tenant->id)->whereIn('m.conversation_id', $accepted->pluck('id'))
            ->whereColumn('m.id', '>', 'membership.joined_after')->whereColumn('m.id', '>', 'membership.read_message_id')
            ->where('m.account_id', '<>', $actor->id)->whereNull('m.deleted_at')->groupBy('m.conversation_id')->selectRaw('m.conversation_id,count(*) as total')->pluck('total','m.conversation_id');
        return $accepted->map(fn ($chat) => $this->conversationDto($actor, $tenant, $chat, $teams, [
            'membership' => $memberships[$chat->id], 'admin' => $admin, 'staff' => in_array((int) $chat->team_season_id, $permittedStaff, true),
            'unread' => (int) ($unread[$chat->id] ?? 0),
        ]))->all();
    }

    public function conversation(Account $actor, Tenant $tenant, int $id): array
    {
        $chat = $this->chat($actor, $tenant, $id);
        $teams = app(MobileAccess::class)->teams($actor, $tenant)->where('ts.id', $chat->team_season_id ?? 0)->get();
        return $this->conversationDto($actor, $tenant, $chat, $teams);
    }

    private function conversationDto(Account $actor, Tenant $tenant, object $chat, $teams, ?array $projection = null): array
    {
        $access = app(MobileAccess::class);
        $membership = $projection['membership'] ?? $this->membership($actor, $tenant, $chat);
        $team = $teams->firstWhere('id', $chat->team_season_id);
        $title = match ($chat->kind) {
            'team' => ($team?->name ?? 'Mannschaft').' · '.($team?->season ?? ''),
            'staff' => 'Trainer · '.($team?->name ?? 'Mannschaft').' · '.($team?->season ?? ''),
            'announcements' => 'Vereinsankündigungen', 'event' => 'Terminbesprechung', default => 'Direktnachricht',
        };
        if ($chat->kind === 'direct') {
            $other = $this->table($tenant, 'chat_memberships')->where('conversation_id', $chat->id)->where('account_id', '<>', $actor->id)->value('account_id');
            $title = Account::whereKey($other)->value('display_name') ?? $title;
        }
        return ['id' => (int) $chat->id, 'kind' => $chat->kind, 'title' => $title,
            'team_season_id' => $chat->team_season_id ? (int) $chat->team_season_id : null,
            'can_moderate' => $projection !== null ? ($chat->kind !== 'direct' && ($projection['admin'] || $projection['staff'])) : app(ChatControls::class)->moderator($actor, $tenant, $chat),
            'other_account_id' => $chat->kind === 'direct' ? (int) $other : null,
            'uploads' => (bool) config('clubunio-mobile.uploads_enabled'),
            'can_write' => $chat->kind !== 'announcements' || ($projection['admin'] ?? $access->admin($actor, $tenant)),
            'can_poll' => $chat->kind !== 'announcements' && ($projection !== null ? ($projection['admin'] || $projection['staff'])
                : ($access->admin($actor, $tenant) || ($chat->team_season_id && $access->teams($actor, $tenant, true)->where('ts.id', $chat->team_season_id)->exists()))),
            'muted' => (bool) $membership->muted,
            'unread' => $projection['unread'] ?? $this->table($tenant, 'chat_messages')->where('conversation_id', $chat->id)
                ->where('id', '>', max($membership->joined_after, $membership->read_message_id))->where('account_id', '<>', $actor->id)->whereNull('deleted_at')->count()];
    }

    public function direct(Account $actor, Tenant $tenant, int $other): object
    {
        $candidate = Account::whereKey($other)->first();
        abort_unless($candidate && app(MobileAccess::class)->canContact($actor, $candidate, $tenant), 404);
        $ids = [(int) $actor->id, $other];
        sort($ids);
        $chat = $this->ensure($tenant, 'direct', key: 'direct:'.implode(':', $ids));
        foreach ($ids as $id) {
            $this->membership(Account::whereKey($id)->firstOrFail(), $tenant, $chat);
        }

        return $chat;
    }

    public function messages(Account $actor, Tenant $tenant, int $chatId, int $after = 0, int $before = 0): array
    {
        $chat = $this->chat($actor, $tenant, $chatId);
        $membership = $this->membership($actor, $tenant, $chat);
        $changeCursor = (int) ($this->table($tenant, 'chat_changes')->where('conversation_id', $chatId)->max('id') ?? 0);
        $query = $this->table($tenant, 'chat_messages')->where('conversation_id', $chat->id)->where('id', '>', max($after, $membership->joined_after));
        if ($before) {
            $query->where('id', '<', $before);
        }
        $rows = $after ? $query->orderBy('id')->limit(51)->get() : $query->orderByDesc('id')->limit(51)->get();
        $hasMore = $rows->count() > 50;
        $rows = $rows->take(50)->sortBy('id');

        return ['data' => array_values($this->messageDtos($actor, $tenant, $rows)),
            'meta' => ['has_more' => $hasMore, 'first_id' => $rows->min('id'), 'last_id' => $rows->max('id'), 'change_cursor' => $changeCursor]];
    }

    public function messageDto(Account $actor, Tenant $tenant, object $row): array
    {
        return $this->messageDtos($actor, $tenant, [$row])[(int) $row->id];
    }

    /** Request-local projection, after the caller has authorized the conversation. */
    public function messageDtos(Account $actor, Tenant $tenant, iterable $messages): array
    {
        $rows = collect($messages);
        if ($rows->isEmpty()) return [];
        $authors = Account::whereIn('id', $rows->pluck('account_id')->unique())->pluck('display_name', 'id');
        $visible = $rows->filter(fn ($row) => !$row->deleted_at);
        $attachments = app(ChatAttachments::class)->forMessages($tenant, $visible->pluck('id')->all());
        $polls = $this->pollDtos($actor, $tenant, $visible->where('kind', 'poll')->pluck('object_id')->unique()->all());
        $moderated = [];
        $foreign = $visible->filter(fn ($row) => (int) $row->account_id !== (int) $actor->id)->pluck('conversation_id')->unique()->all();
        if ($foreign) foreach ($this->table($tenant, 'chat_conversations')->whereIn('id', $foreign)->get() as $chat) {
            $moderated[(int) $chat->id] = app(ChatControls::class)->moderator($actor, $tenant, $chat);
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->id] = ['id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id, 'author_id' => (int) $row->account_id,
                'author_name' => $authors[$row->account_id] ?? null, 'kind' => $row->kind,
                'text' => $row->deleted_at ? '' : Crypt::decryptString($row->body_encrypted), 'deleted' => (bool) $row->deleted_at,
                'revision' => (int) $row->revision, 'created_at' => $row->created_at, 'client_id' => $row->client_id,
                'poll' => $row->kind === 'poll' && !$row->deleted_at ? $polls[(int) $row->object_id] : null,
                'event_id' => !$row->deleted_at && $row->kind === 'event' ? (int) $row->object_id : null,
                'can_edit' => !$row->deleted_at && (int) $row->account_id === (int) $actor->id && in_array($row->kind, ['text', 'announcement'], true)
                    && \Carbon\CarbonImmutable::parse($row->created_at)->gte(now()->subMinutes(30)),
                'can_delete' => !$row->deleted_at && ((int) $row->account_id === (int) $actor->id || ($moderated[(int) $row->conversation_id] ?? false)),
                'attachment' => !$row->deleted_at ? ($attachments[(int) $row->id] ?? null) : null];
        }
        return $result;
    }

    public function send(Account $actor, Tenant $tenant, int $chatId, array $input): object
    {
        $chat = $this->chat($actor, $tenant, $chatId, true);
        $data = Validator::make($input, ['client_id' => 'required|uuid', 'text' => 'required|string|max:4000',
            'kind' => 'nullable|in:text,event,poll,announcement', 'event_id' => 'nullable|integer|min:1',
            'recipient_fingerprint' => 'nullable|string|size:64', 'attachment_id' => 'nullable|uuid',
            'poll' => 'nullable|array', 'poll.options' => 'required_if:kind,poll|array|min:2|max:10',
            'poll.options.*' => 'required|string|max:200|distinct', 'poll.multiple' => 'required_if:kind,poll|boolean',
            'poll.closes_at' => 'required_if:kind,poll|date'])->validate();
        abort_if(trim($data['text']) === '', 422);
        $kind = $data['kind'] ?? 'text';
        abort_if($kind === 'announcement' && $chat->kind !== 'announcements', 422);
        if ($chat->kind === 'announcements') {
            $kind = 'announcement';
        }
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $old = $this->table($tenant, 'chat_messages')->where('account_id', $actor->id)->where('client_id', $data['client_id'])->first();
        if ($old) {
            abort_unless((int) $old->conversation_id === $chatId && hash_equals($old->content_hash, $hash), 409);

            return $old;
        }
        if ($kind === 'announcement') {
            $preview = app(ChatControls::class)->preview($actor, $tenant);
            abort_unless(isset($data['recipient_fingerprint']) && hash_equals($preview['fingerprint'], $data['recipient_fingerprint']), 409);
        }
        if (! empty($data['attachment_id'])) {
            abort_unless(in_array($kind, ['text', 'announcement'], true), 422);
            app(ChatAttachments::class)->ready($actor, $tenant, $chatId, $data['attachment_id']);
        }
        $object = null;
        if ($kind === 'event') {
            $event = app(TeamPlanningService::class)->event($actor, $tenant, (int) ($data['event_id'] ?? 0));
            abort_unless(in_array($chat->kind, ['team', 'staff', 'event'], true) && (int) $chat->team_season_id === (int) $event->team_season_id, 422);
            $object = $event->id;
        }
        if ($kind === 'poll') {
            abort_unless(\Carbon\CarbonImmutable::parse($data['poll']['closes_at'])->isFuture(), 422);
            // First iteration permits creation by responsible staff/admin, voting by all members.
            abort_unless(app(MobileAccess::class)->admin($actor, $tenant)
                || ($chat->team_season_id && app(MobileAccess::class)->teams($actor, $tenant, true)->where('ts.id', $chat->team_season_id)->exists()), 403);
            $options = array_map(fn ($label) => ['id' => (string) Str::uuid(), 'text' => $label], $data['poll']['options']);
            $object = $this->table($tenant, 'chat_polls')->insertGetId(['tenant_id' => $tenant->id, 'conversation_id' => $chat->id,
                'question' => mb_substr($data['text'], 0, 1000), 'options' => json_encode($options),
                'multiple' => $data['poll']['multiple'], 'closes_at' => \Carbon\CarbonImmutable::parse($data['poll']['closes_at'])->toIso8601String()]);
        }
        $recipients = [];
        if ($kind === 'announcement') {
            foreach ($tenant->accounts()->where('active', true)->where('api_only', false)->get() as $recipient) {
                if (app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($recipient, $tenant)) {
                    $this->membership($recipient, $tenant, $chat);
                    $recipients[] = (int) $recipient->id;
                }
            }
        }
        $id = $this->table($tenant, 'chat_messages')->insertGetId(['tenant_id' => $tenant->id, 'conversation_id' => $chat->id,
            'account_id' => $actor->id, 'client_id' => $data['client_id'], 'content_hash' => $hash, 'kind' => $kind,
            'body_encrypted' => Crypt::encryptString($data['text']), 'object_id' => $object, 'created_at' => now()->toIso8601String()]);
        foreach (array_unique($recipients) as $recipient) {
            $this->table($tenant, 'mobile_inbox')->insertOrIgnore(['tenant_id' => $tenant->id, 'account_id' => $recipient,
                'event_key' => 'announcement:'.$id, 'kind' => 'announcement', 'object_id' => $chat->id, 'created_at' => now()->toIso8601String()]);
        }

        $row = $this->table($tenant, 'chat_messages')->where('id', $id)->first();
        if (! empty($data['attachment_id'])) {
            $this->table($tenant, 'chat_attachments')->where('id', $data['attachment_id'])->update(['message_id' => $id]);
        }
        app(ChatControls::class)->record($actor, $tenant, $row, 'send');
        if (config('clubunio-mobile-push.enabled', false)) {
            app(MobilePushOutbox::class)->chatMessage($actor, $tenant, $row);
        }
        return $row;
    }

    public function pollDto(Account $actor, Tenant $tenant, int $id): array
    {
        return $this->pollDtos($actor, $tenant, [$id])[$id];
    }

    private function pollDtos(Account $actor, Tenant $tenant, array $ids): array
    {
        if (!$ids) return [];
        $polls = $this->table($tenant, 'chat_polls')->whereIn('id', $ids)->get(['id','question','options','multiple','closes_at'])->keyBy('id');
        foreach ($ids as $id) abort_unless(isset($polls[$id]), 404);
        $votes = $this->table($tenant, 'chat_votes')->whereIn('poll_id', $ids)->get(['poll_id','account_id','choices'])->groupBy('poll_id');
        $result = [];
        foreach ($polls as $id => $poll) {
            $ballots = $votes[$id] ?? collect();
            $counts = [];
            foreach ($ballots as $vote) foreach (json_decode($vote->choices, true, flags: JSON_THROW_ON_ERROR) as $choice) {
                $counts[$choice] = ($counts[$choice] ?? 0) + 1;
            }
            $result[(int) $id] = ['id' => (int) $id, 'question' => $poll->question, 'multiple' => (bool) $poll->multiple, 'closes_at' => $poll->closes_at,
                'options' => array_map(fn ($o) => $o + ['count' => $counts[$o['id']] ?? 0], json_decode($poll->options, true, flags: JSON_THROW_ON_ERROR)),
                'my_choices' => json_decode($ballots->firstWhere('account_id', $actor->id)?->choices ?? '[]', true), 'voters' => $ballots->count()];
        }
        return $result;
    }

    public function vote(Account $actor, Tenant $tenant, int $id, array $choices): array
    {
        $poll = $this->table($tenant, 'chat_polls')->where('id', $id)->firstOrFail();
        $this->chat($actor, $tenant, (int) $poll->conversation_id);
        $message = $this->table($tenant, 'chat_messages')->where('kind', 'poll')->where('object_id', $id)->whereNull('deleted_at')->first();
        abort_unless($message, 404);
        app(ChatControls::class)->visible($actor, $tenant, (int) $message->id);
        abort_unless(\Carbon\CarbonImmutable::parse($poll->closes_at)->isFuture(), 409);
        $valid = array_column(json_decode($poll->options, true), 'id');
        abort_if(count($choices) !== count(array_unique($choices)) || array_diff($choices, $valid) || (! $poll->multiple && count($choices) > 1), 422);
        $this->table($tenant, 'chat_votes')->updateOrInsert(['poll_id' => $id, 'account_id' => $actor->id],
            ['tenant_id' => $tenant->id, 'choices' => json_encode(array_values($choices)), 'updated_at' => now()->toIso8601String()]);

        app(ChatControls::class)->record($actor, $tenant, $message, 'vote');
        return $this->pollDto($actor, $tenant, $id);
    }

    public function read(Account $actor, Tenant $tenant, int $chatId, int $message): void
    {
        $chat = $this->chat($actor, $tenant, $chatId);
        abort_unless($message === 0 || $this->table($tenant, 'chat_messages')->where('conversation_id', $chat->id)->where('id', $message)->exists(), 422);
        $this->table($tenant, 'chat_memberships')->where('conversation_id', $chat->id)->where('account_id', $actor->id)
            ->where('read_message_id', '<', $message)->update(['read_message_id' => $message]);
    }
}
