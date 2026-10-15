<?php

namespace App\ClubUnio\Resources\RefundResource\Pages;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\RefundResource;
use App\ClubUnio\Services\CreditNoteService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewRefund extends ViewRecord
{
    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        $change = function ($operation, $data) {
            FormMutation::run($this, fn () => app(CreditNoteService::class)->changeRefund(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, (int) $data['revision'], $operation, $data));
            $this->redirect(RefundResource::getUrl('view', ['record' => $this->getRecord()->id]));
        };
        $revision = fn () => TextInput::make('revision')->label('Version')->readOnly()->required();

        return [Action::make('confirm')->label('Manuellen Nachweis erfassen')->icon('heroicon-o-document-check')->visible(fn () => $this->getRecord()->status->code === 'draft')
            ->fillForm(fn () => ['revision' => $this->getRecord()->revision, 'paid_on' => now('Europe/Zurich')->toDateString()])
            ->schema([$revision(), DatePicker::make('paid_on')->label('Nachweisdatum')->required()->maxDate(now('Europe/Zurich')->toDateString()),
                TextInput::make('proof_reference')->label('Eindeutige Nachweisreferenz')->required()->maxLength(120),
                Checkbox::make('attestation')->label('Rückerstattung ausserhalb von ClubUnio durchgeführt; nur den Nachweis übernehmen.')->accepted()])
            ->action(fn ($data) => $change('confirm', $data)),
            Action::make('cancel')->label('Stornieren')->icon('heroicon-o-x-mark')->color('danger')->visible(fn () => $this->getRecord()->status->code === 'draft')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn ($data) => $change('cancel', $data))];
    }
}
