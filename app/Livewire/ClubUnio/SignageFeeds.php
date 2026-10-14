<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Tenant;
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

    public function mount(int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->authorizedTenant();
    }

    private function authorizedTenant(): Tenant
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        abort_unless(Auth::guard('clubunio')->user()?->fresh()?->canAccessTenant($tenant), 403);

        return $tenant;
    }

    public function refreshFeeds(): void
    {
        $tenant = $this->authorizedTenant();
        foreach (['weather', 'instagram'] as $feed) {
            $this->feeds[$feed] = app(SignageFeedService::class)->read(Auth::guard('clubunio')->user(), $tenant, $feed);
        }
    }

    public function render()
    {
        $this->authorizedTenant();

        return view('livewire.clubunio.signage-feeds');
    }
}
