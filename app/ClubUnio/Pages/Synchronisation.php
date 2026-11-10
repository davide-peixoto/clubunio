<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\SyncWorkItem;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class Synchronisation extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable {
        handleTableFilterUpdates as protected handleNativeTableFilterUpdates;
        updatedTableSearch as protected updatedNativeTableSearch;
        updatedTableSort as protected updatedNativeTableSort;
        updatedTableRecordsPerPage as protected updatedNativeTableRecordsPerPage;
    }

    private ?Collection $stateCounts = null;

    protected string $view = 'clubunio.synchronisation';

    protected static ?string $title = 'Synchronisation';

    public string $state = 'open';

    public string $provider = 'all';

    public string $note = '';

    #[Locked]
    public ?int $historyItemId = null;

    #[Locked]
    public ?array $reviewedItem = null;

    public static function getNavigationGroup(): ?string
    {
        return 'Verwaltung';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public static function canAccess(): bool
    {
        return app(BackofficeReadContext::class)->allowsReadPage(Filament::auth()->user(), Filament::getTenant(), adminOnly: true);
    }

    public function updatedState(): void
    {
        $this->tableFilters['state']['value'] = $this->state;
        $this->handleTableFilterUpdates();
    }

    public function updatedProvider(): void
    {
        $this->tableFilters['provider']['value'] = $this->provider;
        $this->handleTableFilterUpdates();
    }

    protected function handleTableFilterUpdates(): void
    {
        $this->state = $this->tableFilters['state']['value'] ?? 'open';
        $this->provider = $this->tableFilters['provider']['value'] ?? 'all';
        $this->resetHistory();
        $this->handleNativeTableFilterUpdates();
    }

    public function updatedTableSearch(): void
    {
        $this->resetHistory();
        $this->updatedNativeTableSearch();
    }

    public function updatedTableSort(): void
    {
        $this->resetHistory();
        $this->updatedNativeTableSort();
    }

    public function updatedTableRecordsPerPage(): void
    {
        $this->resetHistory();
        $this->updatedNativeTableRecordsPerPage();
    }

    public function table(Table $table): Table
    {
        $stateLabels = ['pending' => 'Bereit', 'running' => 'In Bearbeitung', 'attention' => 'Prüfung erforderlich', 'succeeded' => 'Erfolgreich', 'closed' => 'Manuell abgeschlossen'];

        return $table
            ->query(fn (): Builder => $this->workQuery())
            ->columns([
                TextColumn::make('operation')->label('Auftrag')->wrap()->sortable()
                    ->formatStateUsing(fn (string $state): string => __(SyncWorkService::LABELS[$state] ?? $state))
                    ->description(fn (SyncWorkItem $record): string => __('Objekt :object · Auftrag :job', ['object' => $record->object_id, 'job' => $record->id]).($record->context ? ' · '.$record->context : ''))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $operations = array_keys(array_filter(SyncWorkService::LABELS, fn ($label) => str_contains(mb_strtolower(__($label)), mb_strtolower($search))));

                        return $query->where(fn (Builder $query) => $query->where('operation', 'ilike', '%'.$search.'%')
                            ->orWhereIn('operation', $operations)->orWhereRaw('CAST(object_id AS TEXT) ILIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('CAST(id AS TEXT) ILIKE ?', ['%'.$search.'%']));
                    }),
                TextColumn::make('state')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __($stateLabels[$state] ?? $state))
                    ->color(fn (string $state) => StatusAppearance::palette($stateLabels[$state] ?? $state))
                    ->description(fn (SyncWorkItem $record): ?string => $record->error_code ? __(SyncWorkService::ERRORS[$record->error_code] ?? 'Ausführung prüfen') : null),
                TextColumn::make('error_code')->label('Fehlercode')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')->label('Versuche')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Letzter Versuch')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Status')->options(fn (): array => $this->statusOptions())
                    ->default('open')->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'open') {
                        'open' => $query->whereIn('state', ['pending', 'attention', 'running']),
                        'succeeded', 'closed' => $query->where('state', $data['value']),
                        default => $query,
                    }),
                SelectFilter::make('provider')->label('Verbindung')
                    ->options(['all' => __('Alle'), 'bexio' => __('Bexio'), 'sfv' => __('SFV'), 'ai' => __('KI-Training'), 'azure' => __('Azure-Dokumente')])
                    ->default('all')->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'all') {
                        'sfv' => $query->where('operation', 'sfv_schedule'),
                        'bexio' => $query->whereNotIn('operation', ['sfv_schedule', 'training_review', 'azure_upload']),
                        'ai' => $query->where('operation', 'training_review'),
                        'azure' => $query->where('operation', 'azure_upload'),
                        default => $query,
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                Action::make('history')->label('Verlauf')->icon('heroicon-o-clock')
                    ->action(fn (SyncWorkItem $record) => $this->showHistory($record->id)),
                Action::make('view')->label('Rechnung öffnen')->icon('heroicon-o-document-text')
                    ->visible(fn (SyncWorkItem $record): bool => in_array($record->operation, ['invoice_transfer', 'invoice_action', 'invoice_status', 'invoice_sync', 'invoice_result'], true))
                    ->url(fn (SyncWorkItem $record): string => InvoiceResource::getUrl('view', ['record' => $record->object_id])),
                Action::make('details')->label('Spesen öffnen')->icon('heroicon-o-document-text')
                    ->visible(fn (SyncWorkItem $record): bool => in_array($record->operation, ['expense_status', 'expense_sync'], true))
                    ->url(fn (SyncWorkItem $record): string => ExpenseResource::getUrl('view', ['record' => $record->object_id])),
                Action::make('sync')->label('Erneut prüfen')->icon('heroicon-o-arrow-path')
                    ->visible(fn (SyncWorkItem $record): bool => $record->operation !== 'training_review' && (in_array($record->state, ['pending', 'attention'], true)
                        || ($record->state === 'running' && (! $record->lease_until || now()->isAfter($record->lease_until)))))
                    ->requiresConfirmation()
                    ->mountUsing(function (SyncWorkItem $record): void {
                        $this->reviewedItem = ['id' => $record->id, 'revision' => $record->revision, 'action' => 'sync'];
                    })
                    ->modalDescription(__('Diesen Auftrag mit den bestehenden Zuordnungen erneut prüfen beziehungsweise ausführen? Unklare Bexio-Anlagen werden nur abgeglichen.'))
                    ->action(fn (SyncWorkItem $record) => $this->retry($record->id, $this->reviewedRevision($record, 'sync'))),
                Action::make('closeItem')->label('Manuell abschliessen')->icon('heroicon-o-check')->color('gray')
                    ->visible(fn (SyncWorkItem $record): bool => in_array($record->state, ['pending', 'attention'], true))
                    ->modalDescription(__('Auftrag mit Begründung abschliessen? Ein offener Dokumentupload wird damit verworfen, aber verschlüsselt aufbewahrt. Dies bestätigt keine erfolgreiche Synchronisation.'))
                    ->schema([Textarea::make('note')->label('Begründung')->required()->minLength(5)->maxLength(1000)])
                    ->mountUsing(function (SyncWorkItem $record, ?Schema $schema): void {
                        $this->reviewedItem = ['id' => $record->id, 'revision' => $record->revision, 'action' => 'closeItem'];
                        $schema?->fill();
                    })
                    ->action(function (SyncWorkItem $record, array $data): void {
                        $this->note = $data['note'];
                        $this->closeItem($record->id, $this->reviewedRevision($record, 'closeItem'));
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->paginationPageOptions([20, 50, 100])->defaultPaginationPageOption(20)
            ->emptyStateHeading(__('Keine Aufträge in dieser Auswahl.'))->emptyStateDescription(null);
    }

    private function reviewedRevision(SyncWorkItem $record, string $action): int
    {
        abort_unless(($this->reviewedItem['id'] ?? null) === $record->id && ($this->reviewedItem['action'] ?? null) === $action, 422);

        return $this->reviewedItem['revision'];
    }

    protected function workQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $query = app(SyncWorkService::class)->table()->where('tenant_id', Filament::getTenant()->id)
            ->select(['id', 'operation', 'object_id', 'state', 'attempts', 'error_code', 'revision', 'lease_until', 'updated_at'])
            ->selectRaw('case when operation in (?, ?, ?) then encrypted_payload else null end as encrypted_payload', ['sfv_schedule', 'training_review', 'azure_upload']);

        return SyncWorkItem::query()->setQuery($query);
    }

    private function counts(): Collection
    {
        abort_unless(static::canAccess(), 403);

        return $this->stateCounts ??= app(SyncWorkService::class)->table()->where('tenant_id', Filament::getTenant()->id)
            ->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
    }

    private function statusOptions(): array
    {
        $counts = $this->counts();

        return ['open' => __('Offen (:count)', ['count' => ($counts['pending'] ?? 0) + ($counts['attention'] ?? 0) + ($counts['running'] ?? 0)]),
            'succeeded' => __('Erfolgreich (:count)', ['count' => $counts['succeeded'] ?? 0]), 'closed' => __('Manuell abgeschlossen (:count)', ['count' => $counts['closed'] ?? 0]), 'all' => __('Alle')];
    }

    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName === 'page') {
            $this->resetHistory();
        }
    }

    public function showHistory(int $id): void
    {
        $service = app(SyncWorkService::class);
        $service->authorize(Filament::auth()->user(), Filament::getTenant());
        abort_unless($service->table()->where('tenant_id', Filament::getTenant()->id)->where('id', $id)->exists(), 404);
        $this->resetHistory();
        $this->historyItemId = $id;
    }

    public function closeHistory(): void
    {
        $this->resetHistory();
    }

    private function resetHistory(): void
    {
        $this->historyItemId = null;
        $this->resetPage('historyPage');
    }

    public function collectOutstanding(): void
    {
        abort_unless(static::canAccess(), 403);
        $count = app(SyncWorkService::class)->importOutstanding(Filament::auth()->user(), Filament::getTenant());
        $this->stateCounts = null;
        $this->flushCachedTableRecords();
        Notification::make()->title(__(':count neue Prüfaufträge übernommen', ['count' => $count]))->success()->send();
    }

    public function retry(int $id, int $revision): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(SyncWorkService::class)->retry(Filament::auth()->user(), Filament::getTenant(), $id, $revision);
            $row = app(SyncWorkService::class)->table()->where('tenant_id', Filament::getTenant()->id)->find($id);
            Notification::make()->title($row->state === 'succeeded' ? __('Abgleich abgeschlossen') : __('Manuelle Prüfung erforderlich'))
                ->status($row->state === 'succeeded' ? 'success' : 'warning')->send();
        } catch (\Throwable) {
            Notification::make()->title(__('Abgleich nicht abgeschlossen'))->body(__('Auftrag und Protokoll bleiben erhalten. Verbindung, Berechtigungen oder Quelldaten prüfen.'))->warning()->send();
        }
        $this->stateCounts = null;
        $this->flushCachedTableRecords();
    }

    public function closeItem(int $id, int $revision): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['note' => 'required|string|min:5|max:1000']);
        app(SyncWorkService::class)->close(Filament::auth()->user(), Filament::getTenant(), $id, $revision, $this->note);
        $this->note = '';
        $this->stateCounts = null;
        $this->flushCachedTableRecords();
        Notification::make()->title(__('Auftrag mit Begründung abgeschlossen'))->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['state' => 'in:open,succeeded,closed,all', 'provider' => 'in:all,bexio,sfv,ai,azure']);
        $counts = $this->counts();
        $rows = $this->getTableRecords();
        $history = null;
        if ($this->historyItemId !== null) {
            app(SyncWorkService::class)->authorize(Filament::auth()->user(), Filament::getTenant());
            if ($rows->contains('id', $this->historyItemId)) {
                $history = DB::connection('clubunio')->table('clubunio.sync_work_events')->where('work_item_id', $this->historyItemId)
                    ->orderByDesc('id')->paginate(20, ['id', 'actor_id', 'event', 'code', 'encrypted_note', 'created_at'], 'historyPage');
                foreach ($history as $event) {
                    $event->note = $event->encrypted_note ? Crypt::decryptString($event->encrypted_note) : null;
                }
            }
        }
        foreach ($rows as $row) {
            $payload = $row->encrypted_payload === null ? [] : json_decode(Crypt::decryptString($row->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
            $row->context = $row->operation === 'sfv_schedule' ? ($payload['from'].' – '.$payload['to'])
                : ($row->operation === 'training_review' ? ($payload['input']['goal'] ?? '') : '');
            if ($row->operation === 'azure_upload') {
                $row->context = (['member' => 'Mitglied', 'registration' => 'Anmeldung', 'expense' => 'Spesenposition'][$payload['kind']] ?? 'Dokument').' #'.$payload['owner_id'];
            }
        }

        return compact('rows', 'counts', 'history') + ['labels' => SyncWorkService::LABELS];
    }
}
