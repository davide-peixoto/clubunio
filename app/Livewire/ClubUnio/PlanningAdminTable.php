<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\BackofficeReadContext;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\ArrayRecord;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class PlanningAdminTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $tenantId;

    #[Locked]
    public array $workspaceContext;

    public function mount(int $tenantId, array $initialFilters = []): void
    {
        abort_unless(request()->attributes->get('clubunioWorkspace'), 403);
        $this->tenantId = $tenantId;
        $active = app(ActiveTenant::class);
        $this->workspaceContext = request()->session()->get($active->key(request()));
        $this->authorizeTable();
        $this->tableSearch = $initialFilters['q'] ?? '';
        $this->initializeFilters($initialFilters);
    }

    public function hydrate(): void
    {
        $this->authorizeTable();
    }

    /** Recheck the session revision and domain as well as the locked tenant ID. */
    protected function context(): array
    {
        return app(BackofficeReadContext::class)->withoutSnapshot(function () {
            $actor = auth('clubunio')->user()?->fresh();
            abort_unless($actor?->active && ! $actor->api_only, 403);
            $active = app(ActiveTenant::class);
            $active->assertSnapshot(request(), $this->workspaceContext);
            $tenant = $active->resolve(request());
            abort_unless((int) $tenant->id === $this->tenantId && $actor->canAccessTenant($tenant), 403);
            $this->authorizeModule($actor, $tenant);

            return [$actor, $tenant];
        });
    }

    protected function authorizeTable(): void
    {
        $this->context();
    }

    abstract protected function authorizeModule(Account $actor, Tenant $tenant): void;

    abstract protected function initializeFilters(array $filters): void;

    protected function recordsPage(Builder $query, int|string $page, int|string $recordsPerPage)
    {
        // No Eloquent write surface, and never load all rows from a forged page size.
        $perPage = in_array((int) $recordsPerPage, [10, 20, 25, 50, 100], true) ? (int) $recordsPerPage : 25;

        return $query->paginate($perPage, ['*'], $this->getTablePaginationPageName(), max(1, (int) $page))
            ->through(fn ($row) => (array) $row + [ArrayRecord::getKeyName() => (string) $row->id]);
    }

    public function render()
    {
        $this->authorizeTable();

        return view('livewire.clubunio.planning-admin-table');
    }
}
