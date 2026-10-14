<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Table;

class ExpenseResource extends FinanceResource
{
    protected static ?string $model = Expense::class;

    protected static ?string $modelLabel = 'Spesenbeleg';

    protected static ?string $pluralModelLabel = 'Spesenbelege';

    protected static ?string $tenantRelationshipName = 'expenses';

    protected static string $kind = 'expenses';

    public static function table(Table $table): Table
    {
        return parent::table($table)->headerActions([
            Action::make('newExpense')->label('Spesenbeleg erfassen')->icon('heroicon-o-plus')->slideOver()->schema(ExpenseFields::header())
                ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(ExpenseService::class)->save(
                    Filament::auth()->user(), Filament::getTenant(), null, $data))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ExpenseResource\Pages\ListExpenses::route('/'), 'view' => ExpenseResource\Pages\ViewExpense::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\ExpenseLinesRelationManager::class];
    }
}
