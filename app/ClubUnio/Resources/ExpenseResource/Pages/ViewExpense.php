<?php

namespace App\ClubUnio\Resources\ExpenseResource\Pages;

use App\ClubUnio\Resources\ExpenseFields;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\ExpenseNotificationService;
use App\ClubUnio\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [ExpenseFields::transition(),
            Action::make('editHeader')->label('Beleg bearbeiten')->icon('heroicon-o-pencil-square')->slideOver()
                ->visible(fn () => app(ExpenseService::class)->editable(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()))
                ->fillForm(fn () => $this->getRecord()->only(['revision', 'member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']))
                ->schema(ExpenseFields::header())
                ->action(function (array $data) {
                    FormMutation::run($this, fn () => app(ExpenseService::class)->save(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, $data));
                    $this->refreshFormData(['revision', 'member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']);
                }),
            Action::make('notificationPreview')->label('Nachrichtenvorschau')->icon('heroicon-o-envelope')->slideOver()
                ->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')
                ->modalContent(fn () => view('clubunio.messages.expense-preview', ['plan' => app(ExpenseNotificationService::class)->preview(
                    Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id
                )]))];
    }

    public function getTitle(): string
    {
        return 'Spesenbeleg #'.($this->getRecord()->legacy_id ?? $this->getRecord()->id);
    }
}
