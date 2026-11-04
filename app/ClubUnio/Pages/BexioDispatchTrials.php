<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BexioDispatchTrials extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable;

    protected string $view = 'clubunio.bexio-dispatch-trials';

    protected static ?string $title = 'Bexio-Versandprüfung';

    protected static bool $shouldRegisterNavigation = true;

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
        return ProfileUi::allows('bexio');
    }

    public static function canDocumentOperation(string $type, string $function): bool
    {
        $module = match ($type) {
            'invoice' => 'invoices', 'expense' => 'expenses', default => null
        };

        return static::canAccess() && $module !== null
            && ProfileUi::allows($module, $function);
    }

    private function authorizeTrial(int $id, string $function): void
    {
        abort_unless(static::canAccess(), 403);
        $trial = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')
            ->where('tenant_id', Filament::getTenant()->id)->where('actor_id', Filament::auth()->id())->find($id);
        abort_unless($trial && static::canDocumentOperation($trial->object_type, $function), 403);
    }

    public function updatedType(): void
    {
        $this->resetTable();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->documentsQuery())->columns([
            TextColumn::make('id')->label('Dokument')->state(fn ($record) => $record->legacy_id ?? $record->id)
                ->sortable()->searchable(query: fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    foreach (['id', 'legacy_id'] as $column) {
                        $query->orWhereRaw('CAST('.$query->getModel()->qualifyColumn($column).' AS TEXT) ILIKE ?', ['%'.mb_substr(trim($search), 0, 100).'%']);
                    }
                })),
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member',
                fn ($member) => $this->searchMember($member, $search)))
                ->sortable(['last_name', 'first_name']),
        ])->filters([
            SelectFilter::make('trial')->label('Meine Versandprüfung')->options(['checked' => 'Bereits geprüft', 'unchecked' => 'Noch nicht geprüft'])
                ->query(function (Builder $query, array $data): Builder {
                    if (! in_array($data['value'] ?? null, ['checked', 'unchecked'], true)) {
                        return $query;
                    }
                    $trial = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')
                        ->where('tenant_id', Filament::getTenant()->id)->where('actor_id', Filament::auth()->id())
                        ->where('object_type', $this->type)->select('object_id');

                    return ($data['value'] === 'checked')
                        ? $query->whereIn('id', $trial) : $query->whereNotIn('id', $trial);
                }),
        ])->deferFilters(false)->recordActions([
            // Keep the existing guarded method for profiles not mapped by the mounted-action bridge.
            Action::make('check')->label('Prüfen')->icon('heroicon-o-magnifying-glass')->iconButton()->tooltip('Prüfen')
                ->visible(fn () => static::canDocumentOperation($this->type, 'send'))
                ->extraAttributes(['wire:loading.attr' => 'disabled', 'wire:target' => 'check'])
                ->alpineClickHandler(fn ($record) => '$wire.check('.((int) $record->id).')'),
        ])->paginationPageOptions([15, 25, 50])->defaultPaginationPageOption(15)->defaultSort('id', 'desc')
            ->emptyStateHeading('Keine freigegebenen, noch nicht verknüpften Dokumente.')->emptyStateDescription(null);
    }

    private function searchMember(Builder $query, string $search): Builder
    {
        return $query->where('tenant_id', Filament::getTenant()->id)->whereRaw(
            "concat_ws(' ', first_name, last_name) ILIKE ?", ['%'.mb_substr(trim($search), 0, 100).'%']);
    }

    protected function documentsQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense', 'search' => 'string|max:100']);
        $documentTypes = $this->documentTypes();
        if ($documentTypes && ! isset($documentTypes[$this->type])) {
            $this->type = array_key_first($documentTypes);
        }
        $tenant = Filament::getTenant();
        $class = $this->type === 'invoice' ? Invoice::class : Expense::class;
        $field = $this->type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id';
        $binding = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        $documents = $class::where('tenant_id', $tenant->id)->where('active', true)
            ->when(! static::canDocumentOperation($this->type, 'view'), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($binding, fn ($q) => $q->whereNull('legacy_id')->where('member_id', $this->type === 'invoice' ? $binding->member_id : $binding->supplier_id))
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where(fn ($q) => $q->whereNull($field)->orWhere($field, ''))
            ->with('member')->when(trim($this->search) !== '', fn ($q) => $q->whereHas('member', fn ($m) => $this->searchMember($m, $this->search)));
        if ($this->type === 'invoice') {
            $documents->whereNull('legacy_id')->whereHas('workflowStatus', fn ($q) => $q->where('code', 'released'));
        } else {
            $documents->whereHas('status', fn ($q) => $q->where('code', 'Z'));
        }

        return $documents;
    }

    private function documentTypes(): array
    {
        return array_filter(['invoice' => 'Rechnungen', 'expense' => 'Spesen'],
            fn ($type) => static::canDocumentOperation($type, 'view'), ARRAY_FILTER_USE_KEY);
    }

    public function check(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['type' => 'required|in:invoice,expense']);
        abort_unless(static::canDocumentOperation($this->type, 'send'), 403);
        try {
            app(BexioDispatchTrialService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->type, $id);
            Notification::make()->title('Vorschau geprüft')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    public function simulate(int $id): void
    {
        $this->authorizeTrial($id, 'send');
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
        $this->authorizeTrial($id, 'send');
        abort_unless(BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()), 403);
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
        $documentTypes = $this->documentTypes();
        if ($documentTypes && ! isset($documentTypes[$this->type])) {
            $this->type = array_key_first($documentTypes);
            $this->resetPage();
        }
        $tenant = Filament::getTenant();

        $visibleTypes = array_values(array_filter(['invoice', 'expense'], fn ($type) => static::canDocumentOperation($type, 'view')));
        $trials = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('actor_id', Filament::auth()->id())
            ->whereIn('object_type', $visibleTypes)->orderByDesc('id')->limit(30)->get();

        return ['documentTypes' => $documentTypes, 'trials' => $trials,
            'sandboxAdmin' => BexioConnectionService::canManage(Filament::auth()->user()->fresh(), $tenant)
                && DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->exists(),
            'sandboxTrials' => DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->whereNotNull('source_trial_id')->pluck('state', 'source_trial_id'),
            'steps' => DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->whereIn('trial_id', $trials->pluck('id'))->orderBy('id')->get()->groupBy('trial_id')];
    }

    public function resolve(int $id): void
    {
        $this->authorizeTrial($id, 'sync');
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
