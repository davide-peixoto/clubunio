<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\TenantDomainService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class WorkspaceScopedTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $tenantId;

    #[Locked]
    public array $workspaceContext = [];

    #[Locked]
    public string $workspaceHost;

    #[Locked]
    public array $initialFilters = [];

    public function mount(int $tenantId, array $filters = []): void
    {
        abort_unless(request()->attributes->get('clubunioWorkspace'), 403);
        $this->tenantId = $tenantId;
        $this->workspaceContext = request()->session()->get(app(ActiveTenant::class)->key(request()), []);
        $this->workspaceHost = request()->getHost();
        $this->initialFilters = $filters;
        $this->authorizedTenant();
        $this->tableSearch = $filters['q'] ?? '';
    }

    public function hydrate(): void
    {
        $this->authorizedTenant();
    }

    protected function authorizedTenant(): Tenant
    {
        return app(BackofficeReadContext::class)->withoutSnapshot(fn () => $this->resolveWorkspaceTenant());
    }

    private function resolveWorkspaceTenant(): Tenant
    {
        $actor = auth('clubunio')->user()?->fresh();
        abort_unless($actor && $actor->active && ! $actor->api_only, 403);
        abort_unless(request()->getHost() === $this->workspaceHost, 419);
        $active = app(ActiveTenant::class);
        $active->assertSnapshot(request(), $this->workspaceContext);
        $tenant = $active->resolve(request());
        abort_unless((int) $tenant->id === $this->tenantId && $actor->canAccessTenant($tenant), 403);
        $domain = app(TenantDomainService::class)->resolve(request());
        abort_if($domain && ! $domain->is($tenant), 403);

        return $tenant;
    }

    public function getTableRecordsPerPage(): int|string|null
    {
        return in_array((int) $this->tableRecordsPerPage, [10, 25, 50, 100], true) ? (int) $this->tableRecordsPerPage : 25;
    }
}
