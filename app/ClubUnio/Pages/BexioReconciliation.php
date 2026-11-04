<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioReconciliationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BexioReconciliation extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable;

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

        return ProfileUi::allows('bexio');
    }

    public function updatedType(): void
    {
        $this->resetPage();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->documentsQuery())->columns([
            TextColumn::make('legacy_id')->label('Dokument')->state(fn ($record) => $record->legacy_id ?? $record->id)
                ->url(fn ($record) => $this->type === 'invoice'
                    ? InvoiceResource::getUrl('view', ['record' => $record->id])
                    : ExpenseResource::getUrl('view', ['record' => $record->id]))->sortable(),
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member',
                fn ($member) => $member->where(fn ($names) => $names->where('first_name', 'ilike', '%'.mb_substr($search, 0, 100).'%')->orWhere('last_name', 'ilike', '%'.mb_substr($search, 0, 100).'%')))),
            TextColumn::make('status.name')->label('ClubUnio-Status')->badge(),
            TextColumn::make('remote_reference')->label('Bexio-Referenz')->state(fn ($record) => $record->{$this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id'})
                ->wrap()->searchable(query: fn (Builder $query, string $search) => $query->where($this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id', 'ilike', '%'.mb_substr($search, 0, 100).'%')),
        ])->recordActions([
            Action::make('sync')->label('Status prüfen')->icon('heroicon-o-arrow-path')->iconButton()->tooltip(__('Status prüfen'))
                ->visible(fn () => static::canSync($this->type))->action(fn ($record) => $this->check($record->id)),
        ])->paginationPageOptions([15, 25, 50])->defaultPaginationPageOption(15)->defaultSort('id', 'desc')
            ->emptyStateHeading(__('Keine verknüpften Dokumente gefunden.'))->emptyStateDescription(null);
    }

    protected function documentsQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        $tenant = Filament::getTenant();
        $class = $this->type === 'invoice' ? Invoice::class : Expense::class;
        $field = $this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';

        return $class::where('tenant_id', $tenant->id)->where('active', true)->whereNotNull($field)->where($field, '<>', '')->where($field, '<>', '-')
            ->when(! ProfileUi::model($class), fn ($q) => $q->whereRaw('1 = 0'))
            ->when(app(BexioReconciliationService::class)->sandbox($tenant), fn ($q) => $q->whereIn('id', DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('kind', $this->type)->where('state', 'accepted')->select($this->type.'_id')))
            ->with(['member', 'status'])->when(trim($this->search) !== '', fn ($q) => $q->where(function ($q) use ($field) {
                $q->where($field, 'ilike', '%'.trim($this->search).'%')->orWhereHas('member', fn ($m) => $m->where('first_name', 'ilike', '%'.trim($this->search).'%')->orWhere('last_name', 'ilike', '%'.trim($this->search).'%'));
            }));
    }

    public static function canSync(string $type): bool
    {
        $module = match ($type) {
            'invoice' => 'invoices', 'expense' => 'expenses', default => null
        };

        return static::canAccess() && $module !== null
            && ProfileUi::allows('bexio', 'sync')
            && ProfileUi::allows($module, 'sync');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function check(int $id): void
    {
        abort_unless(static::canSync($this->type), 403);
        abort_unless(ProfileUi::allows('bexio', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        app(BexioReconciliationService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->type, $id);
        Notification::make()->title(__('Prüfung abgeschlossen'))->send();
    }

    public function apply(int $id): void
    {
        abort_unless(ProfileUi::allows('bexio', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        $check = DB::connection('clubunio')->table('clubora.bexio_checks')
            ->where('tenant_id', Filament::getTenant()->id)->where('actor_id', Filament::auth()->id())->find($id);
        abort_unless($check && static::canSync($check->object_type), 403);
        try {
            app(BexioReconciliationService::class)->apply(Filament::auth()->user(), Filament::getTenant(), $id);
            Notification::make()->title(__('Status in ClubUnio übernommen'))->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        $documentTypes = array_filter(['invoice' => 'Rechnungen', 'expense' => 'Spesen'],
            fn ($type) => ProfileUi::allows($type === 'invoice' ? 'invoices' : 'expenses'), ARRAY_FILTER_USE_KEY);
        if ($documentTypes && ! isset($documentTypes[$this->type])) {
            $this->type = array_key_first($documentTypes);
            $this->resetPage();
        }
        $tenant = Filament::getTenant();
        $class = $this->type === 'invoice' ? Invoice::class : Expense::class;
        $field = $this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';
        $sandbox = app(BexioReconciliationService::class)->sandbox($tenant);
        $visibleTypes = array_values(array_filter(['invoice', 'expense'], fn ($type) => ProfileUi::allows($type === 'invoice' ? 'invoices' : 'expenses')));
        $checks = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', $tenant->id)
            ->whereIn('object_type', $visibleTypes)->orderByDesc('id')->limit(30)->get();

        return compact('tenant', 'checks', 'field', 'sandbox', 'documentTypes') + ['enabled' => app(BexioReconciliationService::class)->enabled($tenant)];
    }
}
