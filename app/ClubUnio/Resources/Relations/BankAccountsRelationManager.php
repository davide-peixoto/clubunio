<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Bank;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\BankAccountService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Table;

class BankAccountsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'bankAccounts';

    protected static string $kind = 'bankAccounts';

    protected static ?string $title = 'Bankkonten';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $schema = fn () => [
            Hidden::make('revision'),
            Select::make('bank_id')->label('Bank')->required()->searchable()->options(fn () => Bank::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('name')->get()->mapWithKeys(fn ($bank) => [$bank->id => $bank->name.' '.$bank->city])),
            TextInput::make('iban')->label('Neue IBAN')->maxLength(60)->autocomplete(false),
            Toggle::make('active')->label('Aktiv')->default(true),
        ];

        return parent::table($table)->headerActions([
            Action::make('newBank')->label('Bankkonto erfassen')->icon('heroicon-o-plus')->slideOver()->schema($schema)
                ->action(fn (array $data) => FormMutation::run($this, fn () => app(BankAccountService::class)->save(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, null, $data))),
        ])->recordActions([
            ...ActiveRecordActions::make(fn ($record, $data, $active) => FormMutation::run($this, fn () => app(BankAccountService::class)->save(Filament::auth()->user()->fresh(), Filament::getTenant(), $this->getOwnerRecord()->id, $record->id, ['bank_id' => $record->bank_id, 'revision' => $data['revision'], 'active' => $active]))),
            Action::make('editBank')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bankkonto bearbeiten')->slideOver()->schema($schema)
                ->fillForm(fn ($record) => $record->only(['revision', 'bank_id', 'active']))
                ->action(fn ($record, array $data) => FormMutation::run($this, fn () => app(BankAccountService::class)->save(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, $record->id, $data))),
        ]);
    }
}
