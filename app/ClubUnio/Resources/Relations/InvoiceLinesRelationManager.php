<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\InvoiceTaxService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;

class InvoiceLinesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'lines';

    protected static string $kind = 'invoiceLines';

    protected static ?string $title = 'Positionen';

    private function afterInvoiceChange(): void
    {
        $this->financeLinesChanged();
        if ($this->seasonContextId === null) {
            $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->getOwnerRecord()->id]));
        }
    }

    public function table(Table $table): Table
    {
        $editable = fn () => app(InvoiceWorkflowService::class)->editable(
            Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->fresh());
        $schema = [TextInput::make('revision')->label('Version')->readOnly()->required(),
            TextInput::make('description')->label('Bezeichnung')->required()->maxLength(1000),
            TextInput::make('gross_amount')->label('Brutto CHF')->numeric()->required()->minValue(0),
            TextInput::make('discount')->label('Rabatt CHF')->numeric()->required()->default(0)->minValue(0),
            Select::make('bexio_catalog_article_id')->label('Bexio-Artikel')->searchable()
                ->options(fn () => app(BexioCatalogService::class)->articleOptions(Filament::getTenant())),
            Select::make('bexio_catalog_tax_id')->label('Steuerzuordnung')->searchable()
                ->visible(fn () => $this->getOwnerRecord()->tax_mode === 'inclusive')->required(fn () => $this->getOwnerRecord()->tax_mode === 'inclusive')
                ->options(fn () => app(InvoiceTaxService::class)->options(Filament::getTenant(), app(InvoiceTaxService::class)->invoiceDate($this->getOwnerRecord()))),
            Textarea::make('notes')->label('Bemerkungen')->maxLength(4000)];
        $save = function (array $data, ?int $lineId) {
            FormMutation::run($this, fn () => app(InvoiceWorkflowService::class)->change(
                Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id,
                (int) $data['revision'], 'line', array_merge($data, ['line_id' => $lineId])));
            $this->afterInvoiceChange();
        };

        return parent::table($table)->headerActions([
            Action::make('addLine')->label('Position hinzufügen')->icon('heroicon-o-plus')->visible($editable)
                ->fillForm(fn () => ['revision' => $this->getOwnerRecord()->fresh()->revision, 'discount' => 0])->schema($schema)
                ->action(fn (array $data) => $save($data, null)),
        ])->recordActions([
            Action::make('restoreLine')->label('Position wiederherstellen')->icon('heroicon-o-arrow-uturn-left')->iconButton()->tooltip('Position wiederherstellen')
                ->visible(fn ($record) => $editable() && ! $record->active && ! $record->contribution)
                ->fillForm(fn () => ['revision' => $this->getOwnerRecord()->fresh()->revision])
                ->schema([TextInput::make('revision')->label('Version')->readOnly()->required(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(function ($record, array $data) {
                    FormMutation::run($this, fn () => app(InvoiceWorkflowService::class)->change(
                        Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id,
                        (int) $data['revision'], 'restore_line', $data + ['line_id' => $record->id]));
                    $this->afterInvoiceChange();
                }),
            Action::make('editLine')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bearbeiten')->visible(fn ($record) => $editable() && $record->active)
                ->fillForm(fn ($record) => $record->only(['description', 'gross_amount', 'discount', 'notes', 'bexio_catalog_article_id', 'bexio_catalog_tax_id']) + ['revision' => $this->getOwnerRecord()->fresh()->revision])
                ->schema($schema)->action(fn ($record, array $data) => $save($data, $record->id)),
            Action::make('removeLine')->label('Position entfernen')->icon('heroicon-o-trash')->iconButton()->tooltip('Position entfernen')->color('danger')
                ->visible(fn ($record) => $editable() && $record->active && ! $record->contribution)
                ->fillForm(fn () => ['revision' => $this->getOwnerRecord()->fresh()->revision])
                ->schema([TextInput::make('revision')->label('Version')->readOnly()->required(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(function ($record, array $data) {
                    FormMutation::run($this, fn () => app(InvoiceWorkflowService::class)->change(
                        Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id,
                        (int) $data['revision'], 'remove_line', $data + ['line_id' => $record->id]));
                    $this->afterInvoiceChange();
                }),
        ]);
    }
}
