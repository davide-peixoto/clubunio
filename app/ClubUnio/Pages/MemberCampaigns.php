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
    public ?int $targetId = null;
    public int $reminderDays = 7;
    public bool $autoReminders = false;
    public string $templateName = '';
    public ?int $templateId = null;
    public int $operationsLimit = 25;
    #[Locked]
    public ?array $dispatchPreview = null;

    public function previewDispatch(): void
    {
        abort_unless($this->selected, 404);
        $this->saveContent();
        $this->dispatchPreview = app(\App\ClubUnio\Services\CampaignOperations::class)->preview(
            Filament::auth()->user(), Filament::getTenant(), $this->selected);
    }

    public function releaseCampaignAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('releaseCampaign')->label(trans('campaign_ops.release_test'))
            ->requiresConfirmation()->modalDescription(trans('campaign_ops.release_warning'))->action(fn () => $this->release());
    }

    public function saveTemplate(): void
    {
        abort_unless($this->selected, 404);
        $this->saveContent();
        $this->templateId = app(\App\ClubUnio\Services\CampaignOperations::class)->saveTemplate(
            Filament::auth()->user(), Filament::getTenant(), $this->selected, $this->templateName);
    }

    public function useTemplate(): void
    {
        abort_unless($this->selected && $this->templateId, 404);
        app(\App\ClubUnio\Services\CampaignOperations::class)->applyTemplate(
            Filament::auth()->user(), Filament::getTenant(), $this->selected, $this->templateId);
        $this->select($this->selected);
    }

    public function updateTemplate(int $revision): void
    {
        abort_unless($this->selected && $this->templateId, 404);
        $this->saveContent();
        app(\App\ClubUnio\Services\CampaignOperations::class)->updateTemplate(
            Filament::auth()->user(), Filament::getTenant(), $this->selected, $this->templateId, $revision);
    }

    public function archiveTemplate(int $revision): void
    {
        abort_unless($this->templateId, 404);
        app(\App\ClubUnio\Services\CampaignOperations::class)->archiveTemplate(
            Filament::auth()->user(), Filament::getTenant(), $this->templateId, $revision);
        $this->templateId = null;
    }

    public function remindCampaignAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('remindCampaign')->label(trans('campaign_ops.remind'))->requiresConfirmation()
            ->action(function () {
                abort_unless($this->selected, 404);
                app(\App\ClubUnio\Services\CampaignOperations::class)->reminders(
                    Filament::auth()->user(), Filament::getTenant(), $this->selected);
            });
    }

    public function retryDispatchAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('retryDispatch')->label(trans('campaign_ops.retry'))
            ->schema([
                \Filament\Forms\Components\Textarea::make('reason')->label(trans('campaign_ops.reason'))->required()->minLength(10)->maxLength(500),
                \Filament\Forms\Components\Checkbox::make('not_sent')->label(trans('campaign_ops.not_sent'))->accepted()->required(),
            ])->action(function (array $arguments, array $data) {
                abort_unless($this->selected && \Illuminate\Support\Str::isUuid($arguments['dispatch'] ?? ''), 422);
                app(\App\ClubUnio\Services\CampaignOperations::class)->retry(Filament::auth()->user(), Filament::getTenant(),
                    $this->selected, $arguments['dispatch'], $data['reason']);
            });
    }

    public function recordBounceAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('recordBounce')->label(trans('campaign_ops.record_bounce'))
            ->schema([\Filament\Forms\Components\Textarea::make('reason')->label(trans('campaign_ops.reason'))->required()->minLength(10)->maxLength(500)])
            ->action(function (array $arguments, array $data) {
                abort_unless($this->selected && \Illuminate\Support\Str::isUuid($arguments['dispatch'] ?? ''), 422);
                app(\App\ClubUnio\Services\CampaignOperations::class)->manualBounce(Filament::auth()->user(), Filament::getTenant(),
                    $this->selected, $arguments['dispatch'], $data['reason']);
            });
    }

    public function reviewResponseAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('reviewResponse')->label(trans('campaign_ops.review'))->requiresConfirmation()
            ->action(function (array $arguments) {
                abort_unless($this->selected && isset($arguments['recipient']) && is_bool($arguments['approve'] ?? null), 422);
                app(\App\ClubUnio\Services\CampaignResponses::class)->review(Filament::auth()->user(), Filament::getTenant(),
                    $this->selected, (int) $arguments['recipient'], $arguments['approve']);
            });
    }

    public function verifyContactAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('verifyContact')->label(trans('campaign_ops.verify_parent'))->slideOver()
            ->schema([
                \Filament\Forms\Components\Select::make('parent')->label(trans('campaign_ops.parent'))
                    ->options(['mother' => __('Name Mutter'), 'father' => __('Name Vater')])->required(),
                ...array_map(fn ($key) => \Filament\Forms\Components\TextInput::make($key)->label(trans('campaign_ops.fields.'.$key))
                    ->required($key !== 'email')->maxLength($key === 'email' ? 254 : ($key === 'postal_code' ? 20 : 160)),
                    ['name','email','street','postal_code','city']),
                \Filament\Forms\Components\Textarea::make('evidence')->label(trans('campaign_ops.evidence'))->required()->minLength(10)->maxLength(500),
            ])
            ->fillForm(function (array $arguments) {
                abort_unless($this->selected && static::canManage(), 403);
                $tenant = Filament::getTenant();
                $row = app(MemberCampaignService::class)->recipients($tenant, $this->selected)->where('id', $arguments['recipient'] ?? 0)->firstOrFail();
                $member = app(\App\ClubUnio\Services\MemberAccessService::class)->activeMembers($tenant)->findOrFail($row->member_id);
                return ['parent' => 'mother', 'name' => $member->mother_name, 'email' => $member->mother_email,
                    'street' => trim($member->street.' '.$member->house_number), 'postal_code' => $member->postal_code, 'city' => $member->city];
            })
            ->action(function (array $arguments, array $data) {
                abort_unless($this->selected, 404);
                app(\App\ClubUnio\Services\CampaignContact::class)->verify(Filament::auth()->user(), Filament::getTenant(),
                    $this->selected, (int) ($arguments['recipient'] ?? 0), $data);
                $this->dispatchPreview = null;
            });
    }

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
    public static function getNavigationGroup(): ?string { return SocialStudio::getNavigationGroup(); }
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
        $this->targetId = $campaign->target_id;
        $this->reminderDays = (int) $campaign->reminder_days;
        $this->autoReminders = (bool) $campaign->auto_reminders;
        $this->dispatchPreview = null;
        $this->operationsLimit = 25;
        $this->selected = $id;
        $this->limit = 50;
    }

    public function recipient(int $memberId, ?string $channel): void
    {
        abort_unless($this->selected, 404);
        app(MemberCampaignService::class)->recipient(Filament::auth()->user(), Filament::getTenant(), $this->selected, $memberId, $channel);
    }

    public function recipientLocale(int $id, string $locale): void
    {
        abort_unless($this->selected && static::canManage() && array_key_exists($locale, config('clubunio-languages.enabled')), 403);
        $service = app(MemberCampaignService::class);
        DB::connection('clubunio')->transaction(function () use ($service, $id, $locale) {
            $tenant = Filament::getTenant();
            $c = $service->campaigns($tenant)->where('id', $this->selected)->lockForUpdate()->firstOrFail();
            abort_if($c->released_at || $c->revoked_at, 409);
            $service->recipients($tenant, $c->id)->where('id', $id)->update(['locale' => $locale]);
        });
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
        app(\App\ClubUnio\Services\CampaignOperations::class)->configure(Filament::auth()->user(), Filament::getTenant(),
            $this->selected, $this->targetId, $this->reminderDays, $this->autoReminders);
        $this->dispatchPreview = null;
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
                'campaign_recipients.first_opened_at', 'campaign_recipients.printed_at', 'campaign_recipients.verified_at',
                'campaign_recipients.locale',
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
            'operations' => $campaign ? app(\App\ClubUnio\Services\CampaignOperations::class)->cockpit(Filament::auth()->user(), $tenant, $campaign->id, min(1000, max(25, $this->operationsLimit))) : null,
            'templates' => app(\App\ClubUnio\Services\CampaignOperations::class)->templates($tenant)->orderBy('name')->get(),
            'targetOptions' => $this->kind === 'event'
                ? \App\ClubUnio\Models\ClubEvent::where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->pluck('name', 'id')
                : ($this->kind === 'sponsor' ? \App\ClubUnio\Models\SponsorRunEvent::where('tenant_id', $tenant->id)->where('status_code', 'open')->orderBy('title')->pluck('title', 'id') : collect()),
            'recipients' => $recipients, 'matches' => $matches, 'tenant' => $tenant, 'pilot' => $service->isPilot($tenant), 'canManage' => $canManage,
            'mailQueueEnabled' => app(\App\ClubUnio\Services\CampaignMailQueue::class)->enabled(),
            'canEditMembers' => app(\App\ClubUnio\Services\ModulePermissionService::class)->allows(Filament::auth()->user(), $tenant, 'members', 'update'),
            'teamOptions' => $teamOptions, 'count' => $count, 'total' => $campaign ? $service->recipients($tenant, $campaign->id)->count() : 0,
            'hasLetters' => $campaign && $service->recipients($tenant, $campaign->id)->whereIn('channel', ['post','both'])->whereNotNull('token_encrypted')->exists()];
    }
}
