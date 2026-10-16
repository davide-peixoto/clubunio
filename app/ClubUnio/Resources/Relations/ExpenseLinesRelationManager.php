<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\ExpenseFields;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\ExpenseDocumentService;
use App\ClubUnio\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Table;

class ExpenseLinesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'lines';

    protected static string $kind = 'expenseLines';

    protected static ?string $title = 'Positionen';

    public function table(Table $table): Table
    {
        $editable = fn () => app(ExpenseService::class)->editable(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->fresh());

        return parent::table($table)->headerActions([
            Action::make('newLine')->label('Position erfassen')->icon('heroicon-o-plus')->slideOver()->visible($editable)
                ->fillForm(fn () => ['expense_revision' => $this->getOwnerRecord()->fresh()->revision, 'active' => true])
                ->schema(fn () => ExpenseFields::line($this->getOwnerRecord()))->after(fn () => $this->financeLinesChanged())
                ->action(fn (array $data) => FormMutation::run($this, fn () => app(ExpenseService::class)->saveLine(
                    Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, null, $data))),
        ])->recordActions([
            Action::make('uploadDocument')->label('Beleg hinzufügen')->icon('heroicon-o-arrow-up-tray')->iconButton()->tooltip('Beleg hinzufügen')->visible($editable)->slideOver()
                ->after(fn () => $this->financeLinesChanged())
                ->fillForm(fn ($record) => ['revision' => $record->revision, 'expense_revision' => $this->getOwnerRecord()->fresh()->revision])
                ->schema([Hidden::make('revision')->required(), Hidden::make('expense_revision')->required(),
                    FileUpload::make('file')->label('Beleg')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->required()])
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => app(ExpenseDocumentService::class)->add(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision'], (int) $data['expense_revision'], $data['file']))),
            Action::make('document')->label('Beleg')->icon('heroicon-o-document')->iconButton()->tooltip('Beleg öffnen')
                ->visible(fn ($record) => (bool) $record->storage_reference)
                ->url(fn ($record) => route('clubunio.expenses.document', ['tenant' => Filament::getTenant()->slug, 'line' => $record->id]))->openUrlInNewTab(),
            Action::make('editLine')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Position bearbeiten')->slideOver()->visible($editable)
                ->after(fn () => $this->financeLinesChanged())
                ->fillForm(fn ($record) => $record->only(['revision', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes', 'active']) + ['expense_revision' => $this->getOwnerRecord()->fresh()->revision])
                ->schema(fn () => ExpenseFields::line($this->getOwnerRecord()))
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => app(ExpenseService::class)->saveLine(
                    Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, $record->id, $data))),
        ]);
    }
}
