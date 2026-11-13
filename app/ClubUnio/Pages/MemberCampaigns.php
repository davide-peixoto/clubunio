<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\MemberCampaignService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class MemberCampaigns extends Page
{
    protected string $view = 'clubunio.member-campaigns';
    protected static ?string $slug = 'campaigns';

    #[Locked]
    public ?int $selected = null;

    public string $campaignTitle = '';
    public string $channel = 'email';
    public bool $populate = true;
    public string $kind = 'member_update';
    public array $content = [];
    public string $search = '';
    public int $limit = 50;
    public array $filters = [];
    public string $sort = 'name';
    public bool $descending = false;

    public function sortBy(string $column): void
    {
        abort_unless(array_key_exists($column, self::COLUMNS), 422);
        $this->descending = $this->sort === $column && ! $this->descending;
        $this->sort = $column;
    }

    private const COLUMNS = ['name' => 'last_name', 'status' => 'member_status', 'type' => 'member_type', 'team' => 'teams',
        'email' => 'email', 'channel' => 'channel', 'delivery' => 'mail_state'];

    public function deleteDraft(): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        app(MemberCampaignService::class)->deleteDraft(Filament::auth()->user(), Filament::getTenant(), $this->selected);
        $this->selected = null;
    }

    public function addRecipient(int $memberId): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        $tenant = Filament::getTenant();
        $service = app(MemberCampaignService::class);
        $campaign = $service->campaigns($tenant)->where('id', $this->selected)->firstOrFail();
        $member = app(\App\ClubUnio\Services\MemberAccessService::class)->activeMembers($tenant)->findOrFail($memberId);
        $this->recipient($memberId, $service->defaultChannel($campaign->channel, $member));
    }

    public function editMemberAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('editMember')->label(__('Mitglied bearbeiten'))->slideOver()
            ->record(function (array $arguments) {
                abort_unless($this->selected && static::canAccess(), 403);
                $tenant = Filament::getTenant();
                $id = (int) ($arguments['member'] ?? 0);
                abort_unless(app(MemberCampaignService::class)->recipients($tenant, $this->selected)->where('member_id', $id)->exists(), 404);
                return \App\ClubUnio\Resources\MemberResource::getEloquentQuery()->findOrFail($id);
            })
            ->authorize(fn (\App\ClubUnio\Models\Member $record) => \App\ClubUnio\Resources\MemberResource::canEdit($record))
            ->fillForm(fn (\App\ClubUnio\Models\Member $record) => $record->attributesToArray() + ['_version' => \App\ClubUnio\Services\MemberService::version($record)])
            ->schema(fn (\Filament\Schemas\Schema $schema) => \App\ClubUnio\Resources\MemberResource::form($schema))
            ->action(fn (\App\ClubUnio\Models\Member $record, array $data) => \App\ClubUnio\Resources\FormMutation::run($this,
                fn () => app(\App\ClubUnio\Services\MemberService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)));
    }

    public static function getNavigationLabel(): string { return __('campaign.title'); }
    public static function getNavigationIcon(): string { return 'heroicon-o-megaphone'; }
    public function getTitle(): string { return __('campaign.title'); }
    public static function canAccess(): bool
    {
        return app(MemberCampaignService::class)->canView(Filament::auth()->user(), Filament::getTenant());
    }

    private static function canManage(): bool
    {
        return app(MemberCampaignService::class)->allowed(Filament::auth()->user(), Filament::getTenant());
    }

    public function create(): void
    {
        $this->validate(['campaignTitle' => 'required|string|max:160', 'channel' => 'in:email,post,both'], [], [
            'campaignTitle' => __('campaign.name'), 'channel' => __('campaign.channel'),
        ]);
        $this->selected = app(MemberCampaignService::class)->create(
            Filament::auth()->user(), Filament::getTenant(), $this->campaignTitle, $this->channel, $this->populate,
        );
        $this->campaignTitle = '';
        $this->select($this->selected);
    }

    public function select(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $campaign = app(MemberCampaignService::class)->campaigns(Filament::getTenant())->where('id', $id)->firstOrFail();
        $this->kind = $campaign->kind;
        $this->content = json_decode($campaign->content ?? '{}', true);
        $this->selected = $id;
        $this->limit = 50;
    }

    public function recipient(int $memberId, ?string $channel): void
    {
        abort_unless($this->selected, 404);
        app(MemberCampaignService::class)->recipient(Filament::auth()->user(), Filament::getTenant(), $this->selected, $memberId, $channel);
    }

    public function release(): void
    {
        abort_unless($this->selected, 404);
        $this->saveContent();
        app(MemberCampaignService::class)->release(Filament::auth()->user(), Filament::getTenant(), $this->selected);
    }

    public function saveContent(): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        app(MemberCampaignService::class)->saveContent(Filament::auth()->user(), Filament::getTenant(), $this->selected, $this->kind, $this->content);
    }

    public function markPosted(int $recipient): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        $tenant = Filament::getTenant();
        $service = app(MemberCampaignService::class);
        $service->openCampaign($tenant, $this->selected);
        $service->recipients($tenant, $this->selected)->where('id', $recipient)
            ->whereIn('channel', ['post', 'both'])->whereNull('posted_at')->update(['posted_at' => now()]);
    }

    public function testMail(int $recipient): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        app(\App\ClubUnio\Services\CampaignMailQueue::class)->enqueue(Filament::auth()->user(), Filament::getTenant(), $this->selected, $recipient);
    }

    public function markBounce(int $recipient): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        app(\App\ClubUnio\Services\CampaignMailQueue::class)->bounce(Filament::auth()->user(), Filament::getTenant(), $this->selected, $recipient);
    }

    public function revoke(): void
    {
        abort_unless($this->selected && static::canManage(), 403);
        $tenant = Filament::getTenant();
        $id = $this->selected;
        DB::connection('clubunio')->transaction(function () use ($tenant, $id) {
            \App\ClubUnio\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $service = app(MemberCampaignService::class);
            $service->campaigns($tenant)->where('id', $id)->update(['revoked_at' => now()]);
            $service->recipients($tenant, $id)->update(['token_hash' => null, 'token_encrypted' => null]);
        });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $tenant = Filament::getTenant();
        $service = app(MemberCampaignService::class);
        $campaign = $this->selected ? $service->campaigns($tenant)->where('id', $this->selected)->firstOrFail() : null;
        $db = DB::connection('clubunio');
        $season = app(\App\ClubUnio\Mobile\MobileSeasonScope::class)->current($tenant);
        $teams = $db->table('clubunio.team_players as player')
            ->join('clubunio.team_seasons as team', fn ($join) => $join->on('team.id', '=', 'player.team_season_id')->on('team.tenant_id', '=', 'player.tenant_id'))
            ->where('player.tenant_id', $tenant->id)->where('player.active', true)->where('team.active', true)
            ->where('team.season_id', $season?->id ?? -1)->groupBy('player.member_id')
            ->selectRaw("player.member_id, string_agg(DISTINCT team.label, ', ' ORDER BY team.label) as teams, array_agg(DISTINCT team.id) as team_ids");
        $teamOptions = $campaign && $season ? $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)
            ->where('season_id', $season->id)->where('active', true)->orderBy('label')->pluck('label', 'id') : collect();
        $rows = $campaign ? $db->table('clubunio.campaign_recipients')
            ->where('campaign_recipients.tenant_id', $tenant->id)->where('campaign_recipients.campaign_id', $campaign->id)
            ->join('clubunio.members as member', fn ($join) => $join->on('member.id', '=', 'campaign_recipients.member_id')->on('member.tenant_id', '=', 'campaign_recipients.tenant_id'))
            ->leftJoin('clubunio.member_change_requests as request', fn ($join) => $join->on('request.id', '=', 'campaign_recipients.request_id')->on('request.tenant_id', '=', 'campaign_recipients.tenant_id'))
            ->leftJoin('clubunio.member_statuses as status', fn ($join) => $join->on('status.id', '=', 'member.status_id')->on('status.tenant_id', '=', 'member.tenant_id'))
            ->leftJoin('clubunio.member_types as type', fn ($join) => $join->on('type.id', '=', 'member.type_id')->on('type.tenant_id', '=', 'member.tenant_id'))
            ->leftJoin('clubunio.billing_recipients as billing', fn ($join) => $join->on('billing.id', '=', 'member.billing_recipient_id')->on('billing.tenant_id', '=', 'member.tenant_id')->where('billing.active', true))
            ->leftJoinSub($teams, 'roster', 'roster.member_id', '=', 'member.id')
            ->select('campaign_recipients.id', 'campaign_recipients.member_id', 'campaign_recipients.channel', 'campaign_recipients.mail_state',
                'campaign_recipients.request_id', 'campaign_recipients.acknowledged_at', 'campaign_recipients.posted_at', 'campaign_recipients.mail_address_hash',
                'member.first_name', 'member.last_name', 'member.street', 'member.postal_code', 'member.city', 'request.state as request_state',
                'status.name as member_status', 'type.name as member_type', 'roster.teams', 'roster.team_ids')
            ->selectRaw("CASE WHEN member.billing_recipient_id IS NULL OR billing.email_field = 'email' THEN trim(member.email)
                WHEN billing.email_field = 'mother_email' THEN trim(member.mother_email) WHEN billing.email_field = 'father_email' THEN trim(member.father_email) END AS email") : null;
        $query = $rows ? $db->query()->fromSub($rows, 'recipients') : null;
        foreach (self::COLUMNS as $key => $column) {
            $filter = $this->filters[$key] ?? '';
            if (! $query || ! is_string($filter) || trim($filter) === '') continue;
            $value = '%'.mb_substr(trim($filter), 0, 120).'%';
            if ($key === 'name') {
                $query->whereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$value]);
            } elseif ($key === 'team') {
                if (ctype_digit($filter) && $teamOptions->has((int) $filter)) {
                    $query->whereRaw('cast(? as bigint) = any(team_ids)', [$filter]);
                }
            } else {
                $query->where($column, in_array($key, ['channel','delivery'], true) ? '=' : 'ilike',
                    in_array($key, ['channel','delivery'], true) ? $filter : $value);
            }
        }
        $count = $query ? (clone $query)->count() : 0;
        $recipients = $query ? $query->orderBy(self::COLUMNS[$this->sort] ?? 'last_name', $this->descending ? 'desc' : 'asc')
            ->orderBy('id')->limit(min(5000, max(50, $this->limit)))->get() : collect();
        $canManage = static::canManage();
        $matches = $canManage && $campaign && ! $campaign->released_at && ! $campaign->revoked_at && mb_strlen(trim($this->search)) >= 2
            ? app(\App\ClubUnio\Services\MemberAccessService::class)->activeMembers($tenant)->where(function ($q) {
                $q->whereLike('first_name', '%'.mb_substr($this->search, 0, 80).'%')
                    ->orWhereLike('last_name', '%'.mb_substr($this->search, 0, 80).'%');
            })->orderBy('last_name')->limit(15)->get(['id', 'first_name', 'last_name']) : collect();

        return ['campaigns' => $service->campaigns($tenant)->latest('id')->limit(100)->get(), 'campaign' => $campaign,
            'recipients' => $recipients, 'matches' => $matches, 'tenant' => $tenant, 'pilot' => $service->isPilot($tenant), 'canManage' => $canManage,
            'mailQueueEnabled' => app(\App\ClubUnio\Services\CampaignMailQueue::class)->enabled(),
            'canEditMembers' => app(\App\ClubUnio\Services\ModulePermissionService::class)->allows(Filament::auth()->user(), $tenant, 'members', 'update'),
            'teamOptions' => $teamOptions, 'count' => $count, 'total' => $campaign ? $service->recipients($tenant, $campaign->id)->count() : 0,
            'hasLetters' => $campaign && $service->recipients($tenant, $campaign->id)->whereIn('channel', ['post','both'])->whereNotNull('token_encrypted')->exists()];
    }
}
