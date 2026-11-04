<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SponsorRunBillingService;
use App\ClubUnio\Services\SponsorRunMoney;
use App\ClubUnio\Services\SponsorRunService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class SponsorRunBilling extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable;

    protected string $view = 'clubunio.sponsor-run-billing';

    protected static ?string $title = 'Sponsorenlauf · Abrechnung';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public ?int $event = null;

    public string $state = '';

    public array $catalogs = [];

    public static function canAccess(): bool
    {
        return ProfileUi::allows('sponsor_runs') && ProfileUi::allows('bexio');
    }

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function loadCatalogs(): void
    {
        $this->catalogs = app(SponsorRunBillingService::class)->catalogs(Filament::auth()->user(), Filament::getTenant());
    }

    public function configureAction(): Action
    {
        $mapping = ['user_id' => 'users', 'language_id' => 'languages', 'bank_account_id' => 'banks', 'currency_id' => 'currencies',
            'payment_type_id' => 'payment_types', 'unit_id' => 'units', 'tax_id' => 'taxes', 'account_id' => 'accounts'];
        $fields = [Toggle::make('enabled')->label('Abrechnung aktiv')->default(false)];
        foreach (SponsorRunBillingService::FIELDS as $key => $label) {
            if (isset($mapping[$key])) {
                $fields[] = Select::make($key)->label($label)->searchable()->options(fn () => collect($this->catalogs[$mapping[$key]] ?? [])
                    ->mapWithKeys(fn ($r) => [$r['id'] => ($r['name'] ?? $r['name_1'] ?? $r['number'] ?? $r['id']).' · '.$r['id']])->all())
                    ->getOptionLabelUsing(fn ($value) => $value ? __('Zuordnung #:id', ['id' => $value]) : null);
            } elseif ($key === 'mwst_type') {
                $fields[] = Select::make($key)->label($label)->options([0 => __('Inklusive MWST'), 1 => __('Steuerbefreit'), 2 => __('Ohne MWST')]);
            } else {
                $fields[] = TextInput::make($key)->label($label)->integer()->minValue(0)->maxValue(365);
            }
        }

        return Action::make('configure')->label('Buchhaltung zuordnen')->icon('heroicon-o-cog-6-tooth')->slideOver()
            ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->fillForm(fn () => app(SponsorRunBillingService::class)->configuration(Filament::getTenant()))->schema($fields)
            ->action(fn (array $data) => FormMutation::run($this, fn () => app(SponsorRunBillingService::class)->saveConfiguration(Filament::auth()->user(), Filament::getTenant(), $data)));
    }

    public function prepareAction(): Action
    {
        return Action::make('prepare')->label('Rechnung vorbereiten')->icon('heroicon-o-document-plus')->slideOver()
            ->visible(fn () => ProfileUi::allows('sponsor_runs', 'approve'))
            ->schema([Select::make('pledge_id')->label('Freigegebene Zusage')->required()->searchable()
                ->options(function () {
                    $event = $this->getTableFilterState('event_id')['value'] ?? null;

                    return $event ? collect(app(SponsorRunService::class)->billablePledges(Filament::auth()->user(), Filament::getTenant(), (int) $event))->pluck('label', 'id')->all() : [];
                })])
            ->action(fn (array $data) => FormMutation::run($this, fn () => app(SponsorRunBillingService::class)->prepare(Filament::auth()->user(), Filament::getTenant(), (int) $data['pledge_id'])));
    }

    public function operationAction(?string $operation = null): Action
    {
        return Action::make($operation ?? 'operation')->label('Rechnungsaktion bestätigen')->requiresConfirmation()->slideOver()
            ->schema(fn (array $arguments, ?array $record) => [Hidden::make('revision')->default($record['revision'] ?? $arguments['revision'] ?? 0),
                ...(($operation ?? $arguments['action'] ?? '') === 'link-contact' ? [TextInput::make('contact_id')->label('Bestätigte Bexio-Kontakt-ID')->integer()->minValue(1)->required()] : []),
                ...(($operation ?? $arguments['action'] ?? '') === 'confirm-sent' ? [Textarea::make('reason')->label('Nachweis der manuellen Versandprüfung')->required()->minLength(10)->maxLength(1000)] : [])])
            ->modalDescription(fn (array $arguments) => ($operation ?? $arguments['action'] ?? '') === 'send'
                ? __('Die Testrechnung wird ausschliesslich an admin@svsissach.ch versendet.') : __('Bestehende Zuordnungen und aktuellen Beleg prüfen und Aktion bestätigen.'))
            ->action(function (array $arguments, array $data, ?array $record) use ($operation) {
                try {
                    app(SponsorRunBillingService::class)->execute(Filament::auth()->user(), Filament::getTenant(),
                        (int) ($record['id'] ?? $arguments['id']), (int) $data['revision'], (string) ($operation ?? $arguments['action']), $data);
                    Notification::make()->title(__('Rechnungsaktion abgeschlossen'))->success()->send();
                } catch (\Throwable) {
                    Notification::make()->title(__('Aktion nicht bestätigt'))->body(__('Prüfauftrag bleibt in Synchronisation erhalten. Keine ungeprüfte Neuerstellung.'))->warning()->send();
                }
            });
    }

    private function canOperate(): bool
    {
        return BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()) && ProfileUi::allows('sponsor_runs', 'approve');
    }

    public function table(Table $table): Table
    {
        $actions = [Action::make('pdf')->label('Bexio-PDF öffnen')->icon('heroicon-o-document-text')->iconButton()->tooltip(__('Bexio-PDF öffnen'))
            ->visible(fn (array $record) => $record['remote_id'] && ProfileUi::allows('sponsor_runs', 'download'))
            ->url(fn (array $record) => route('clubunio.sponsor.billing.pdf', ['invoice' => $record['id']]))->openUrlInNewTab()];
        foreach ([
            'link-contact' => ['Kontakt zuordnen', 'user-plus', ['prepared']],
            'create-contact' => ['Externen Kontakt erstellen', 'user-circle', ['prepared']],
            'create-draft' => ['Bexio-Entwurf erstellen', 'document-plus', ['contact_ready']],
            'issue' => ['Ausstellen', 'check-circle', ['draft']],
            'send' => ['Testrechnung versenden', 'paper-airplane', ['issued']],
            'cancel' => ['Stornieren', 'x-circle', ['issued', 'sent']],
            'confirm-sent' => ['Versand manuell nachweisen', 'clipboard-document-check', ['send_uncertain']],
            'refresh-preview' => ['Empfängersnapshot und Buchhaltung neu prüfen', 'arrow-path-rounded-square', ['prepared', 'contact_ready']],
            'sync' => ['Ergebnis und Zahlungsstatus abgleichen', 'arrow-path', null],
        ] as $operation => [$label, $icon, $states]) {
            $actions[] = $this->operationAction($operation)->label($label)->icon('heroicon-o-'.$icon)->iconButton()->tooltip(__($label))
                ->visible(fn (array $record) => $this->canOperate() && ($states === null || in_array($record['state'], $states, true)));
        }

        return $table->records(function (?string $search, ?array $filters, int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) {
            $recordsPerPage = in_array((string) $recordsPerPage, ['10', '20', '50'], true) ? (int) $recordsPerPage : 20;
            $page = max(1, $page);
            $service = app(SponsorRunBillingService::class);
            $tenant = Filament::getTenant();
            $service->authorize(Filament::auth()->user(), $tenant);
            $query = $service->table($tenant)->when($this->state !== '', fn ($q) => $q->where('state', $this->state))
                ->when($filters['event_id']['value'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
                ->when($filters['state']['value'] ?? null, fn ($q, $state) => $q->where('state', $state));
            if (filled($search)) {
                $query->where(function ($q) use ($search, $tenant) {
                    $term = '%'.mb_substr(trim($search), 0, 100).'%';
                    $q->whereIn('pledge_id', DB::connection('clubunio')->table('clubora.sponsor_run_pledges')->where('tenant_id', $tenant->id)->where('sponsor_name', 'ilike', $term)->select('id'))
                        ->orWhereIn('event_id', DB::connection('clubunio')->table('clubora.sponsor_run_events')->where('tenant_id', $tenant->id)->where('title', 'ilike', $term)->select('id'));
                });
            }
            $column = in_array($sortColumn, ['id', 'total_cents', 'state', 'updated_at'], true) ? $sortColumn : 'id';
            $rows = $query->orderBy($column, $sortDirection === 'asc' ? 'asc' : 'desc')
                ->paginate($recordsPerPage, ['id', 'event_id', 'pledge_id', 'state', 'revision', 'total_cents', 'remote_id', 'paid_cents', 'credit_cents', 'updated_at'], 'page', $page);
            $events = DB::connection('clubunio')->table('clubora.sponsor_run_events')->where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('event_id'))->pluck('title', 'id');
            $sponsors = DB::connection('clubunio')->table('clubora.sponsor_run_pledges')->where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('pledge_id'))->pluck('sponsor_name', 'id');
            $rows->setCollection($rows->getCollection()->map(fn ($row) => (array) $row + ['__key' => (string) $row->id,
                'sponsor' => $sponsors[$row->pledge_id] ?? 'Zusage #'.$row->pledge_id, 'event_title' => $events[$row->event_id] ?? '']));

            return $rows;
        })->columns([
            TextColumn::make('sponsor')->label('Zusage')->description(fn (array $record) => $record['event_title'])->searchable(),
            TextColumn::make('total_cents')->label('Betrag')->formatStateUsing(fn ($state) => SponsorRunMoney::format($state))->sortable(),
            TextColumn::make('state')->label('Status')->badge()->formatStateUsing(fn ($state) => __(SponsorRunBillingService::STATES[$state] ?? $state))
                ->color(fn ($state) => StatusAppearance::palette(SponsorRunBillingService::STATES[$state] ?? $state))->sortable(),
            TextColumn::make('remote_id')->label('Bexio')->prefix('#')->placeholder('-'),
            TextColumn::make('paid_cents')->label('Bezahlt')->formatStateUsing(fn ($state) => SponsorRunMoney::format($state))->placeholder('-'),
        ])->filters([
            SelectFilter::make('event_id')->label('Anlass')->default(fn () => $this->event)->options(fn () => DB::connection('clubunio')->table('clubora.sponsor_run_events')->where('tenant_id', Filament::getTenant()->id)->orderByDesc('id')->pluck('title', 'id')->all()),
            SelectFilter::make('state')->label('Status')->options(array_map(fn ($name) => __($name), SponsorRunBillingService::STATES)),
        ])->deferFilters(false)->headerActions([
            $this->prepareAction(),
            Action::make('loadCatalogs')->label('Bexio-Kataloge laden')->icon('heroicon-o-arrow-path')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))->action(fn () => $this->loadCatalogs()),
            $this->configureAction(),
            Action::make('sponsorRuns')->label('Sponsorenlauf')->icon('heroicon-o-arrow-left')->url(url('/sponsor-runs'))->color('gray'),
        ])->recordActions($actions)->paginated([10, 20, 50])->defaultPaginationPageOption(20)->emptyStateHeading(__('Keine vorbereiteten Sponsorenrechnungen.'));
    }
}
