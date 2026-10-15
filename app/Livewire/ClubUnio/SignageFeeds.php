<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PublicWebService;
use App\ClubUnio\Services\SignageFeedService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Isolate]
class SignageFeeds extends Component
{
    #[Locked]
    public int $tenantId;

    #[Locked]
    public array $feeds = [];

    #[Locked]
    public bool $publicDisplay = false;

    #[Locked]
    public bool $kiosk = false;

    #[Locked]
    public ?string $feed = null;

    public function mount(int $tenantId, bool $publicDisplay = false, bool $kiosk = false, ?string $feed = null): void
    {
        $this->tenantId = $tenantId;
        $this->publicDisplay = $publicDisplay;
        abort_unless($feed === null || in_array($feed, ['weather', 'instagram'], true), 404);
        $this->kiosk = $kiosk;
        $this->feed = $feed;
        $this->authorizedTenant();
    }

    private function authorizedTenant(): Tenant
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        if ($this->publicDisplay) {
            app(PublicWebService::class)->authorize($tenant, 'signage');
        } else {
            abort_unless(Auth::guard('clubunio')->user()?->fresh()?->canAccessTenant($tenant), 403);
        }

        return $tenant;
    }

    public function refreshFeeds(): void
    {
        $tenant = $this->authorizedTenant();
        foreach ($this->feed ? [$this->feed] : ['weather', 'instagram'] as $feed) {
            $this->feeds[$feed] = $this->publicDisplay ? app(SignageFeedService::class)->readPublic($tenant, $feed)
                : app(SignageFeedService::class)->read(Auth::guard('clubunio')->user(), $tenant, $feed);
        }
    }

    public function render()
    {
        $this->authorizedTenant();

        return view($this->kiosk ? 'livewire.clubunio.signage-kiosk-feed' : 'livewire.clubunio.signage-feeds');
    }
}
