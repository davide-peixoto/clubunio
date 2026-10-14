<?php

namespace App\ClubUnio\Resources\CreditNoteResource\Pages;

use App\ClubUnio\Resources\CreditNoteResource;
use App\ClubUnio\Resources\FinancialAdjustmentActions;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\CreditNoteService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        $revision = fn () => TextInput::make('revision')->label('Version')->required()->readOnly();
        $change = function ($operation, $data) {
            FormMutation::run($this, fn () => app(CreditNoteService::class)->change(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, (int) $data['revision'], $operation, $data));
            $this->redirect(CreditNoteResource::getUrl('view', ['record' => $this->getRecord()->id]));
        };

        return [
            Action::make('release')->label('Intern freigeben')->icon('heroicon-o-check')->visible(fn () => $this->getRecord()->status->code === 'draft')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision()])->action(fn ($data) => $change('release', $data)),
            Action::make('confirm')->label('Bexio-Zuordnung bestätigen')->icon('heroicon-o-link')->visible(fn () => $this->getRecord()->status->code === 'released' && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(), TextInput::make('external_reference')->label('Gutschriftsnummer aus Bexio')->required()->maxLength(120),
                    Checkbox::make('attestation')->label('Diese Gutschrift ist in Bexio genau dieser Rechnung zugeordnet.')->accepted()])->action(fn ($data) => $change('confirm', $data)),
            FinancialAdjustmentActions::refund(fn () => $this->getRecord()->invoice_id, fn () => $this->getRecord()->id)->visible(fn () => $this->getRecord()->status->code === 'confirmed' && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant())),
            Action::make('cancel')->label('Stornieren')->icon('heroicon-o-x-mark')->color('danger')->visible(fn () => in_array($this->getRecord()->status->code, ['draft', 'released'], true))
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn ($data) => $change('cancel', $data)),
        ];
    }
}
