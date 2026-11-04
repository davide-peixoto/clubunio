<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BexioSandbox extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable;

    protected string $view = 'clubunio.bexio-sandbox';

    protected static ?string $title = 'Bexio-Sandbox';

    protected static bool $shouldRegisterNavigation = false;

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
        return app(BackofficeReadContext::class)->allowsReadPage(Filament::auth()->user(), Filament::getTenant(), adminOnly: true);
    }

    public function prepare(string $kind): void
    {
        $this->run(fn ($service) => $service->prepare(Filament::auth()->user(), Filament::getTenant(), $kind));
    }

    public function prepareParent(): void
    {
        $this->run(function ($service) {
            $actor = Filament::auth()->user();
            $tenant = Filament::getTenant();
            $invoiceId = app(BexioGeneralSandboxFixtureService::class)->prepareParent($actor, $tenant);
            if (! DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->exists()) {
                $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $invoiceId);
                $service->prepareTrial($actor, $tenant, $trial);
            }
        });
    }

    public function invoiceAction(int $invoiceId, string $operation): void
    {
        $this->run(fn () => app(BexioInvoiceActionService::class)->execute(Filament::auth()->user(), Filament::getTenant(), $invoiceId, $operation));
    }

    public function invoiceResult(int $invoiceId): void
    {
        $this->run(fn () => app(BexioInvoiceActionService::class)->reconcile(Filament::auth()->user(), Filament::getTenant(), $invoiceId));
    }

    public function financeAction(string $kind, int $objectId, string $operation): void
    {
        $this->run(fn () => app(BexioFinancialActionService::class)->execute(Filament::auth()->user(), Filament::getTenant(), $kind, $objectId, $operation));
    }

    public function financeResult(string $kind, int $objectId): void
    {
        $this->run(fn () => app(BexioFinancialActionService::class)->reconcile(Filament::auth()->user(), Filament::getTenant(), $kind, $objectId));
    }

    public function send(int $id): void
    {
        $this->run(fn ($service) => $service->send(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    public function reconcile(int $id): void
    {
        $this->run(fn ($service) => $service->reconcile(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    public function verifyReconnection(): void
    {
        $this->run(fn ($service) => $service->verifyReconnection(Filament::auth()->user(), Filament::getTenant()));
    }

    public function resumeUpload(int $id): void
    {
        $this->run(fn ($service) => $service->resumeRejectedUpload(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    private function run(callable $action): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $action(app(BexioSandboxDispatchService::class));
            Notification::make()->title(__('Prüfung abgeschlossen'))->body(__('Der aktuelle Stand ist im Übertragungsprotokoll sichtbar.'))->send();
        } catch (\Throwable $error) {
            // Provider responses and secrets never enter Livewire state or flash messages.
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
            Notification::make()->title(__('Aktion nicht abgeschlossen'))->body(__('Verbindung, Testbeleg oder Berechtigung prüfen. Prüfcode: :status', ['status' => $status]))->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [];
    }

    public function table(Table $table): Table
    {
        $stateLabels = ['ready' => 'Bereit', 'running' => 'Gestartet', 'accepted' => 'Angelegt und geprüft',
            'rejected' => 'Abgelehnt', 'uncertain' => 'Unklar – Wiederholung gesperrt'];

        return $table->records($this->dispatchRecords(...))->columns([
            TextColumn::make('document')->label('Testbeleg')->state(fn (array $record) => $record['kind'] === 'invoice' ? __('Rechnung #:id', ['id' => $record['invoice_id']]) : __('Spesen #:id', ['id' => $record['expense_id']]))
                ->description(fn (array $record) => $record['source_trial_id'] ? __('Allgemeine Versandprüfung') : __('Fester Testbeleg'))->searchable()->sortable(),
            TextColumn::make('amount')->label('Betrag CHF')->state(fn (array $record) => number_format(($record['expected_cents'] ?? ($record['kind'] === 'invoice' ? 2500 : 1500)) / 100, 2, '.', "'")),
            TextColumn::make('state')->label('Status')->badge()->formatStateUsing(fn (string $state) => __($stateLabels[$state] ?? $state))
                ->color(fn (string $state) => StatusAppearance::palette($stateLabels[$state] ?? $state))->sortable(),
            ViewColumn::make('protocol')->label('Übertragungsprotokoll')->view('clubunio.bexio-sandbox-protocol'),
            TextColumn::make('remote_id')->label('Bexio-ID')->placeholder('-')->wrap()->searchable()->sortable(),
            ViewColumn::make('operations')->label('Aktion')->view('clubunio.bexio-sandbox-operations'),
        ])->filters([
            SelectFilter::make('kind')->label('Dokument')->options(['invoice' => __('Rechnungen'), 'expense' => __('Spesen')]),
            SelectFilter::make('state')->label('Status')->options([
                'ready' => __('Bereit'), 'running' => __('Gestartet'), 'accepted' => __('Angelegt und geprüft'),
                'rejected' => __('Abgelehnt'), 'uncertain' => __('Unklar – Wiederholung gesperrt'),
            ]),
            SelectFilter::make('source')->label('Herkunft')->options(['trial' => __('Allgemeine Versandprüfung'), 'fixture' => __('Fester Testbeleg')]),
        ])->deferFilters(false)->paginated([15, 25, 50])->defaultPaginationPageOption(15)->defaultSort('id', 'asc')
            ->emptyStateHeading(__('Noch keine passenden Web-Testbelege.'))->emptyStateDescription(null);
    }

    private function dispatchRecords(?string $search, ?array $filters, int $page, int $recordsPerPage, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator
    {
        abort_unless(static::canAccess(), 403);
        $tenant = Filament::getTenant();
        $search = mb_substr(trim($search ?? ''), 0, 100);
        $rows = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
            ->where('tenant_id', $tenant->id)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                foreach (['id', 'invoice_id', 'expense_id', 'remote_id'] as $column) {
                    $query->orWhereRaw('CAST('.$column.' AS TEXT) ILIKE ?', ['%'.$search.'%']);
                }
            }));
        foreach (['kind', 'state'] as $filter) {
            if (filled($value = $filters[$filter]['value'] ?? null)) {
                $rows->where($filter, $value);
            }
        }
        if (($filters['source']['value'] ?? null) === 'trial') {
            $rows->whereNotNull('source_trial_id');
        } elseif (($filters['source']['value'] ?? null) === 'fixture') {
            $rows->whereNull('source_trial_id');
        }
        $direction = $sortDirection === 'desc' ? 'desc' : 'asc';
        if ($sortColumn === 'document') {
            $rows->orderByRaw('COALESCE(invoice_id, expense_id) '.$direction);
        } elseif (in_array($sortColumn, ['id', 'state', 'remote_id'], true)) {
            $rows->orderBy($sortColumn, $direction);
        }
        $rows = $rows->orderBy('id')->paginate($recordsPerPage, [
            'id', 'kind', 'invoice_id', 'expense_id', 'state', 'remote_id', 'http_status',
            'attachment_id', 'source_trial_id', 'expected_cents',
        ], 'page', $page);

        $context = [
            'eligibleInvoices' => Invoice::where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('invoice_id')->filter())->whereHas('workflowStatus', fn ($q) => $q->where('code', 'released'))->pluck('id')->all(),
            'eligibleExpenses' => Expense::where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('expense_id')->filter())
                ->whereHas('status', fn ($q) => $q->where('code', 'Z'))->whereNotNull('payment_released_on')->pluck('id')->all(),
            'financialActions' => DB::connection('clubunio')->table('clubora.bexio_financial_actions')->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id'),
            'balances' => DB::connection('clubunio')->table('clubora.bexio_balances')->where('tenant_id', $tenant->id)
                ->where(fn ($query) => $query->where(fn ($q) => $q->where('object_type', 'invoice')->whereIn('object_id', $rows->pluck('invoice_id')->filter()))
                    ->orWhere(fn ($q) => $q->where('object_type', 'expense')->whereIn('object_id', $rows->pluck('expense_id')->filter())))
                ->get()->keyBy(fn ($balance) => $balance->object_type.':'.$balance->object_id),
            'actions' => DB::connection('clubunio')->table('clubora.bexio_invoice_actions')->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id'), 'steps' => DB::connection('clubunio')->table('clubora.bexio_sandbox_steps')
                ->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id')];
        $rows->setCollection($rows->getCollection()->map(fn ($row) => (array) $row + [
            '__key' => (string) $row->id,
            'steps' => $context['steps'][$row->id] ?? collect(),
            'actions' => $context['actions'][$row->id] ?? collect(),
            'financialActions' => $context['financialActions'][$row->id] ?? collect(),
            'balance' => $context['balances'][$row->kind.':'.($row->invoice_id ?? $row->expense_id)] ?? null,
            'eligibleInvoice' => in_array($row->invoice_id, $context['eligibleInvoices']),
            'eligibleExpense' => in_array($row->expense_id, $context['eligibleExpenses']),
        ]));

        return $rows;
    }
}
