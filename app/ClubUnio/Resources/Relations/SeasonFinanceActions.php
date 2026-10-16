<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ExpenseFields;
use App\ClubUnio\Resources\ExpenseResource\Pages\ViewExpense;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

trait SeasonFinanceActions
{
    public function isReadOnly(): bool
    {
        return ! in_array($this->getOwnerRecord()::class, [Season::class, Member::class, Team::class, TeamSeason::class], true);
    }

    private function financeTeams($owner)
    {
        return TeamSeason::where('tenant_id', Filament::getTenant()->id)
            ->when($owner instanceof Season, fn ($q) => $q->where('season_id', $owner->id))
            ->when($owner instanceof Team, fn ($q) => $q->where('team_id', $owner->id))
            ->when($owner instanceof TeamSeason, fn ($q) => $q->whereKey($owner->id));
    }

    #[On('season-finance-updated')]
    public function refreshSeasonFinance(): void
    {
        $this->flushCachedTableRecords();
    }

    private function financeSchema(bool $create = false): array
    {
        if (static::$kind === 'expenses') {
            return array_map(function ($field) {
                if ($field->getName() === 'team_season_id') {
                    $field->options(fn () => $this->financeTeams($this->getOwnerRecord())->where('active', true)->orderBy('label')->pluck('label', 'id'));
                    if ($this->getOwnerRecord() instanceof TeamSeason) {
                        $field->default($this->getOwnerRecord()->id)->disabled()->dehydrated();
                    }
                }
                if ($field->getName() === 'member_id' && $this->getOwnerRecord() instanceof Member) {
                    return Hidden::make('member_id')->default($this->getOwnerRecord()->id);
                }

                return $field;
            }, ExpenseFields::header());
        }

        return [
            ...($create ? [$this->getOwnerRecord() instanceof Member ? Hidden::make('member_id')->default($this->getOwnerRecord()->id) : MemberResource::memberSelect('member_id')->required()] : [Hidden::make('revision')->required()]),
            ...($create && $this->getOwnerRecord() instanceof Member ? [Select::make('season_id')->label('Saison')->searchable()->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderByDesc('name')->pluck('name', 'id'))] : []),
            Select::make('invoice_category_id')->label('Kategorie')->required($create)
                ->options(fn () => InvoiceCategory::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('sort_order')->pluck('name', 'id')),
            Select::make('tax_mode')->label('Steuerbehandlung')->required()->default('exempt')
                ->options(['exempt' => 'Steuerbefreit', 'inclusive' => 'Endpreise inklusive Steuer']),
            Textarea::make('notes')->label('Bemerkungen')->maxLength(4000),
        ];
    }

    private function withSeasonFinanceActions(Table $table): Table
    {
        if ($this->isReadOnly()) {
            return $table;
        }
        $invoice = static::$kind === 'invoices';
        $table->getColumn('legacy_id')?->getStateUsing(fn ($record) => $record->legacy_id ?? $record->id);
        $service = $invoice ? InvoiceWorkflowService::class : ExpenseService::class;
        $editable = fn ($record) => app($service)->editable(Filament::auth()->user(), Filament::getTenant(), $record);

        return $table->recordUrl(null)->recordAction('positions')->headerActions([
            Action::make('createFinance')->label($invoice ? 'Rechnung erstellen' : 'Spesenbeleg erfassen')->icon('heroicon-o-plus')
                ->visible(! $invoice || $this->getOwnerRecord() instanceof Season || $this->getOwnerRecord() instanceof Member)
                ->slideOver()->schema(fn () => $this->financeSchema(true))
                ->action(fn (array $data) => FormMutation::run($this, fn () => $this->saveSeasonFinance(null, $data))),
        ])->recordActions([
            Action::make('positions')->label('Positionen')->icon('heroicon-o-list-bullet')->iconButton()->tooltip('Positionen anzeigen und bearbeiten')
                ->modalHeading(fn ($record) => ($invoice ? 'Rechnung #' : 'Spesenbeleg #').($record->legacy_id ?? $record->id))
                ->slideOver()->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')
                ->modalContent(fn ($record) => view('clubunio.finance.season-positions', [
                    'component' => $invoice ? InvoiceLinesRelationManager::class : ExpenseLinesRelationManager::class,
                    'record' => $record, 'contextId' => $this->getOwnerRecord()->id, 'contextType' => $this->getOwnerRecord()::class,
                    'pageClass' => $invoice ? ViewInvoice::class : ViewExpense::class,
                ])),
            Action::make('editFinance')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Beleg bearbeiten')->slideOver()->visible($editable)
                ->fillForm(fn ($record) => $record->only($invoice ? ['revision', 'notes', 'tax_mode', 'invoice_category_id'] : ['revision', 'member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']))
                ->schema(fn () => $this->financeSchema())
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $this->saveSeasonFinance($record->id, $data))),
            ...($invoice ? [
                Action::make('releaseFinance')->label('Intern freigeben')->icon('heroicon-o-check')->iconButton()->tooltip('Intern freigeben')->visible($editable)
                    ->modalDescription('Interne Freigabe ohne Bexio-Übertragung oder Versand. Danach sind Positionen gesperrt.')
                    ->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')->required()])
                    ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $this->saveSeasonFinance($record->id, $data, 'release'))),
                Action::make('cancelFinance')->label('Stornieren')->icon('heroicon-o-x-mark')->color('danger')->iconButton()->tooltip('Stornieren')
                    ->visible(fn ($record) => $editable($record) || ($record->active && ! $record->legacy_id && ! $record->bexio_invoice_id && $record->workflowStatus?->code === 'released'))
                    ->fillForm(fn ($record) => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision')->required(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                    ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $this->saveSeasonFinance($record->id, $data, 'cancel'))),
                Action::make('pdf')->label('PDF-Vorschau')->icon('heroicon-o-document')->iconButton()->tooltip('PDF-Vorschau')->slideOver()->modalWidth('5xl')
                    ->visible(fn ($record) => $record->active && ($record->bexio_invoice_id || $record->workflow_status_id))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')->modalContent(fn ($record) => view('clubunio.invoices.preview', [
                        'viewerUrl' => route('clubunio.invoices.preview', ['tenant' => Filament::getTenant()->slug, 'invoice' => $record->id, 'source' => $record->bexio_invoice_id ? 'bexio' : 'local']),
                        'url' => route('clubunio.invoices.pdf', ['tenant' => Filament::getTenant()->slug, 'invoice' => $record->id, 'source' => $record->bexio_invoice_id ? 'bexio' : 'local']),
                    ])),
            ] : [ExpenseFields::transition()->iconButton()->tooltip('Status ändern')
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $this->saveSeasonFinance($record->id, $data, 'transition')))]),
        ]);
    }

    public function saveSeasonFinance(?int $id, array $data, string $operation = 'header'): void
    {
        DB::connection('clubunio')->transaction(function () use ($id, $data, $operation) {
            $tenant = Filament::getTenant();
            $actor = Filament::auth()->user()->fresh();
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor?->canAccessTenant($tenant) && ! $this->isReadOnly(), 403);
            $owner = $this->getOwnerRecord()::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($this->getOwnerRecord()->id);
            $relation = $owner->{static::$kind}();
            $row = $id ? $relation->where($relation->getRelated()->qualifyColumn('tenant_id'), $tenant->id)->lockForUpdate()->findOrFail($id) : null;
            if ($owner instanceof Member) {
                $data['member_id'] = $owner->id;
            }
            abort_unless($id || $operation === 'header', 403);
            if (static::$kind === 'invoices') {
                abort_unless(in_array($operation, ['header', 'release', 'cancel'], true), 403);
                if ($id) {
                    app(InvoiceWorkflowService::class)->change($actor, $tenant, $row->id, (int) ($data['revision'] ?? 0), $operation, $data);
                } else {
                    abort_unless($owner instanceof Season || $owner instanceof Member, 403);
                    if ($owner instanceof Season) {
                        $data['season_id'] = $owner->id;
                    }
                    app(InvoiceWorkflowService::class)->create($actor, $tenant, $data);
                }
            } else {
                abort_unless(in_array($operation, ['header', 'transition'], true), 403);
                if ($operation === 'transition') {
                    abort_unless($row, 404);
                    app(ExpenseService::class)->transition($actor, $tenant, $row->id, $data);
                } else {
                    if ($owner instanceof TeamSeason) {
                        $data['team_season_id'] = $owner->id;
                    }
                    $this->financeTeams($owner)->lockForUpdate()->findOrFail($data['team_season_id'] ?? null);
                    app(ExpenseService::class)->save($actor, $tenant, $row?->id, $data);
                }
            }
        });
    }
}
