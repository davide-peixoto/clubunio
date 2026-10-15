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

    public function mount(int $tenantId, bool $publicDisplay = false): void
    {
        $this->tenantId = $tenantId;
        $this->publicDisplay = $publicDisplay;
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
        foreach (['weather', 'instagram'] as $feed) {
            $this->feeds[$feed] = $this->publicDisplay ? app(SignageFeedService::class)->readPublic($tenant, $feed)
                : app(SignageFeedService::class)->read(Auth::guard('clubunio')->user(), $tenant, $feed);
        }
    }

    public function render()
    {
        $this->authorizedTenant();

        return view('livewire.clubunio.signage-feeds');
    }
}
