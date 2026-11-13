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
    public string $search = '';
    public int $limit = 50;

    public static function getNavigationLabel(): string { return __('campaign.title'); }
    public function getTitle(): string { return __('campaign.title'); }
    public static function canAccess(): bool
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
    }

    public function select(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        app(MemberCampaignService::class)->campaigns(Filament::getTenant())->where('id', $id)->firstOrFail();
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
        app(MemberCampaignService::class)->release(Filament::auth()->user(), Filament::getTenant(), $this->selected);
    }

    public function markPosted(int $recipient): void
    {
        abort_unless($this->selected && static::canAccess(), 403);
        $tenant = Filament::getTenant();
        $service = app(MemberCampaignService::class);
        $service->openCampaign($tenant, $this->selected);
        $service->recipients($tenant, $this->selected)->where('id', $recipient)
            ->whereIn('channel', ['post', 'both'])->whereNull('posted_at')->update(['posted_at' => now()]);
    }

    public function testMail(int $recipient): void
    {
        abort_unless($this->selected && static::canAccess(), 403);
        app(MemberCampaignService::class)->testMail(Filament::getTenant(), $this->selected, $recipient);
    }

    public function revoke(): void
    {
        abort_unless($this->selected && static::canAccess(), 403);
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
        $recipients = $campaign ? $service->recipients($tenant, $campaign->id)
            ->join('clubunio.members as member', 'member.id', '=', 'campaign_recipients.member_id')
            ->leftJoin('clubunio.member_change_requests as request', 'request.id', '=', 'campaign_recipients.request_id')
            ->select('campaign_recipients.*', 'member.first_name', 'member.last_name', 'member.email', 'member.street', 'member.postal_code', 'member.city', 'request.state as request_state')
            ->orderBy('campaign_recipients.id')->limit(min(5000, max(50, $this->limit)))->get() : collect();
        $matches = $campaign && ! $campaign->released_at && ! $campaign->revoked_at && mb_strlen(trim($this->search)) >= 2
            ? $service->eligible($tenant)->where(function ($q) {
                $q->whereLike('first_name', '%'.mb_substr($this->search, 0, 80).'%')
                    ->orWhereLike('last_name', '%'.mb_substr($this->search, 0, 80).'%');
            })->orderBy('last_name')->limit(15)->get(['id', 'first_name', 'last_name']) : collect();

        return ['campaigns' => $service->campaigns($tenant)->latest('id')->limit(100)->get(), 'campaign' => $campaign,
            'recipients' => $recipients, 'matches' => $matches, 'tenant' => $tenant, 'pilot' => $service->isPilot($tenant),
            'count' => $campaign ? $service->recipients($tenant, $campaign->id)->count() : 0];
    }
}
