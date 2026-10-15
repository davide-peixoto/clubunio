<?php

namespace App\ClubUnio\Resources\CreditNoteResource\Pages;

use App\ClubUnio\Models\CustomerCredit;
use App\ClubUnio\Resources\CreditNoteResource;
use App\ClubUnio\Resources\CustomerCreditResource;
use App\ClubUnio\Resources\FinancialAdjustmentActions;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\CustomerCreditService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
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
            Action::make('viewCustomerCredit')->label('Kundenguthaben anzeigen')->icon('heroicon-o-wallet')
                ->visible(fn () => $this->getRecord()->customerCredit !== null)
                ->url(fn () => CustomerCreditResource::getUrl('view', ['record' => $this->getRecord()->customerCredit?->id])),
            Action::make('customerCredit')->label('Als Kundenguthaben führen')->icon('heroicon-o-wallet')
                ->visible(fn () => $this->getRecord()->status->code === 'released' && ! CustomerCredit::where('credit_note_id', $this->getRecord()->id)->exists()
                    && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(),
                    TextInput::make('external_reference')->label('Bexio-Gutschriftnummer')->required()->regex('/^GS-[0-9]{1,20}$/iD'),
                    Checkbox::make('attestation')->label('Gutschrift in Bexio angelegt: Gesamtbetrag und Steuer entsprechen dieser Originalkorrektur. Restbetrag ist noch nicht automatisch verifiziert.')->accepted()])
                ->action(function ($data) {
                    $credit = FormMutation::run($this, fn () => app(CustomerCreditService::class)->register(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, $data));
                    $this->redirect(CustomerCreditResource::getUrl('view', ['record' => $credit->id]));
                }),
            Action::make('release')->label('Intern freigeben')->icon('heroicon-o-check')->visible(fn () => $this->getRecord()->status->code === 'draft')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision()])->action(fn ($data) => $change('release', $data)),
            Action::make('confirm')->label('Bexio-Zuordnung bestätigen')->icon('heroicon-o-link')->visible(fn () => $this->getRecord()->status->code === 'released' && ! $this->getRecord()->customerCredit && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(), Select::make('external_reference')->label('Verrechnete Bexio-Gutschrift')->required()->options(function () {
                    $remote = app(CreditNoteService::class)->remote(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->invoice_id);

                    return collect($remote['credit_allocations'])->filter(fn ($row) => $row['reference'] !== null)
                        ->mapWithKeys(fn ($row) => [$row['reference'] => $row['reference'].' · CHF '.number_format($row['amount_cents'] / 100, 2, '.', "'")])->all();
                }),
                    Checkbox::make('attestation')->label('Diese Gutschrift ist in Bexio genau dieser Rechnung zugeordnet.')->accepted()])->action(fn ($data) => $change('confirm', $data)),
            FinancialAdjustmentActions::refund(fn () => $this->getRecord()->invoice_id, fn () => $this->getRecord()->id)->visible(fn () => $this->getRecord()->status->code === 'confirmed' && ! $this->getRecord()->customerCredit && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant())),
            Action::make('cancel')->label('Stornieren')->icon('heroicon-o-x-mark')->color('danger')->visible(fn () => in_array($this->getRecord()->status->code, ['draft', 'released'], true) && ! $this->getRecord()->customerCredit)
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn ($data) => $change('cancel', $data)),
        ];
    }
}
