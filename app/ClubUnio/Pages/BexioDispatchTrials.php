<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class BexioDispatchTrials extends Page
{
    use WithPagination;

    protected string $view = 'clubunio.bexio-dispatch-trials';

    protected static ?string $title = 'Bexio-Versandprüfung';

    public string $type = 'invoice';

    public string $search = '';

    public string $outcome = 'simulated';

    public string $resolution = 'not_created';

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-beaker';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() && (Filament::auth()->user()?->fresh()?->canAccessTenant(Filament::getTenant()) ?? false);
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function check(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense']);
        try {
            app(BexioDispatchTrialService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->type, $id);
            Notification::make()->title('Vorschau geprüft')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    public function simulate(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['outcome' => 'required|in:simulated,rejected,uncertain']);
        try {
            app(BexioDispatchTrialService::class)->simulate(Filament::auth()->user(), Filament::getTenant(), $id, $this->outcome);
            Notification::make()->title('Testlauf abgeschlossen; kein Versand')->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    public function sendSandbox(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $service = app(BexioSandboxDispatchService::class);
        $dispatch = $service->prepareTrial(Filament::auth()->user(), Filament::getTenant(), $id);
        $service->send(Filament::auth()->user(), Filament::getTenant(), $dispatch);
        $this->redirect(BexioSandbox::getUrl());
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        $tenant = Filament::getTenant();
        $class = $this->type === 'invoice' ? Invoice::class : Expense::class;
        $field = $this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';
        $binding = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        $documents = $class::where('tenant_id', $tenant->id)->where('active', true)
            ->when($binding, fn ($q) => $q->whereNull('legacy_id')->where('member_id', $this->type === 'invoice' ? $binding->member_id : $binding->supplier_id))
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where(fn ($q) => $q->whereNull($field)->orWhere($field, ''))
            ->with('member')->when(trim($this->search) !== '', fn ($q) => $q->whereHas('member', fn ($m) => $m->where('tenant_id', $tenant->id)->where(fn ($n) => $n->where('first_name', 'ilike', '%'.trim($this->search).'%')->orWhere('last_name', 'ilike', '%'.trim($this->search).'%'))));
        if ($this->type === 'invoice') {
            $documents->whereNull('legacy_id')->whereHas('workflowStatus', fn ($q) => $q->where('code', 'released'));
        } else {
            $documents->whereHas('status', fn ($q) => $q->where('code', 'Z'));
        }

        $trials = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('actor_id', Filament::auth()->id())->orderByDesc('id')->limit(30)->get();

        return ['documents' => $documents->orderByDesc('id')->paginate(15), 'trials' => $trials,
            'sandboxAdmin' => BexioConnectionService::canManage(Filament::auth()->user()->fresh(), $tenant)
                && DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->exists(),
            'sandboxTrials' => DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->whereNotNull('source_trial_id')->pluck('state', 'source_trial_id'),
            'steps' => DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->whereIn('trial_id', $trials->pluck('id'))->orderBy('id')->get()->groupBy('trial_id')];
    }

    public function resolve(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['resolution' => 'required|in:found,not_created']);
        try {
            app(BexioDispatchTrialService::class)->resolveSimulation(Filament::auth()->user(), Filament::getTenant(), $id, $this->resolution);
            Notification::make()->title('Testlauf geklärt; kein echter Bexio-Abgleich')->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }
}
