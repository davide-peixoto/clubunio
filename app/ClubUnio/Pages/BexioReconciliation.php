<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioReconciliationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class BexioReconciliation extends Page
{
    use WithPagination;

    protected string $view = 'clubunio.bexio-reconciliation';

    protected static ?string $title = 'Bexio-Abgleich';

    public string $type = 'invoice';

    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-path';
    }

    public static function canAccess(): bool
    {
        if (Filament::getTenant() && app(BexioReconciliationService::class)->sandbox(Filament::getTenant())) {
            return app(BackofficeReadContext::class)->allowsReadPage(Filament::auth()->user(), Filament::getTenant(), adminOnly: true);
        }

        return \App\ClubUnio\Resources\ProfileUi::allows('bexio');
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public static function canSync(string $type): bool
    {
        $module = match ($type) { 'invoice' => 'invoices', 'expense' => 'expenses', default => null };

        return static::canAccess() && $module !== null
            && \App\ClubUnio\Resources\ProfileUi::allows('bexio', 'sync')
            && \App\ClubUnio\Resources\ProfileUi::allows($module, 'sync');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function check(int $id): void
    {
        abort_unless(static::canSync($this->type), 403);
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('bexio', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        app(BexioReconciliationService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->type, $id);
        Notification::make()->title('Prüfung abgeschlossen')->send();
    }

    public function apply(int $id): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('bexio', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        $check = DB::connection('clubunio')->table('clubora.bexio_checks')
            ->where('tenant_id', Filament::getTenant()->id)->where('actor_id', Filament::auth()->id())->find($id);
        abort_unless($check && static::canSync($check->object_type), 403);
        try {
            app(BexioReconciliationService::class)->apply(Filament::auth()->user(), Filament::getTenant(), $id);
            Notification::make()->title('Status in ClubUnio übernommen')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        $documentTypes = array_filter(['invoice' => 'Rechnungen', 'expense' => 'Spesen'],
            fn ($type) => \App\ClubUnio\Resources\ProfileUi::allows($type === 'invoice' ? 'invoices' : 'expenses'), ARRAY_FILTER_USE_KEY);
        if ($documentTypes && ! isset($documentTypes[$this->type])) {
            $this->type = array_key_first($documentTypes);
            $this->resetPage();
        }
        $tenant = Filament::getTenant();
        $class = $this->type === 'invoice' ? Invoice::class : Expense::class;
        $field = $this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';
        $sandbox = app(BexioReconciliationService::class)->sandbox($tenant);
        $documents = $class::where('tenant_id', $tenant->id)->where('active', true)->whereNotNull($field)->where($field, '<>', '')->where($field, '<>', '-')
            ->when(! \App\ClubUnio\Resources\ProfileUi::model($class), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($sandbox, fn ($q) => $q->whereIn('id', DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('kind', $this->type)->where('state', 'accepted')->select($this->type.'_id')))
            ->with(['member', 'status'])->when(trim($this->search) !== '', fn ($q) => $q->where(function ($q) use ($field) {
                $q->where($field, 'ilike', '%'.trim($this->search).'%')->orWhereHas('member', fn ($m) => $m->where('first_name', 'ilike', '%'.trim($this->search).'%')->orWhere('last_name', 'ilike', '%'.trim($this->search).'%'));
            }))->orderByDesc('id')->paginate(15);
        $visibleTypes = array_values(array_filter(['invoice', 'expense'], fn ($type) => \App\ClubUnio\Resources\ProfileUi::allows($type === 'invoice' ? 'invoices' : 'expenses')));
        $checks = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', $tenant->id)
            ->whereIn('object_type', $visibleTypes)->orderByDesc('id')->limit(30)->get();

        return compact('tenant', 'documents', 'checks', 'field', 'sandbox', 'documentTypes') + ['enabled' => app(BexioReconciliationService::class)->enabled($tenant)];
    }
}
