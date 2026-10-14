<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\CreditNoteService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class FinancialAdjustmentActions
{
    public static function credit(Closure $invoiceId): Action
    {
        return Action::make('createCredit')->label('Gutschrift erfassen')->icon('heroicon-o-receipt-refund')
            ->fillForm(fn () => ['request_id' => (string) Str::uuid(), 'revision' => Invoice::where('tenant_id', Filament::getTenant()->id)->findOrFail($invoiceId())->revision,
                'lines' => app(CreditNoteService::class)->availableLines(Filament::auth()->user(), Filament::getTenant(), $invoiceId())])
            ->schema([Hidden::make('request_id')->required(), TextInput::make('revision')->label('Rechnungsversion')->readOnly()->required(),
                Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000),
                Repeater::make('lines')->label('Gutschriftspositionen')->addActionLabel('Position hinzufügen')->minItems(1)->maxItems(100)->defaultItems(1)->reorderable(false)->schema([
                    Select::make('invoice_line_id')->label('Rechnungsposition')->required()->searchable()->options(fn () => Invoice::where('tenant_id', Filament::getTenant()->id)->findOrFail($invoiceId())->activeLines()->pluck('description', 'id')),
                    TextInput::make('amount')->label('Gutschrift CHF')->numeric()->required()->minValue(0.01)->maxValue(999999999.99)])])
            ->action(function (array $data, $livewire) use ($invoiceId) {
                $note = FormMutation::run($livewire, fn () => app(CreditNoteService::class)->create(Filament::auth()->user(), Filament::getTenant(), $invoiceId(), $data));
                $livewire->redirect(CreditNoteResource::getUrl('view', ['record' => $note->id]));
            });
    }

    public static function refund(Closure $invoiceId, ?Closure $creditId = null): Action
    {
        return Action::make('createRefund')->label('Rückerstattung vormerken')->icon('heroicon-o-arrow-uturn-left')
            ->fillForm(fn () => ['request_id' => (string) Str::uuid()])->schema([Hidden::make('request_id')->required(), TextInput::make('amount')->label('Betrag CHF')->numeric()->required()->minValue(0.01),
                Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
            ->action(function (array $data, $livewire) use ($invoiceId, $creditId) {
                $refund = FormMutation::run($livewire, fn () => app(CreditNoteService::class)->createRefund(Filament::auth()->user(), Filament::getTenant(), $invoiceId(), $data + ['credit_note_id' => $creditId?->__invoke()]));
                $livewire->redirect(RefundResource::getUrl('view', ['record' => $refund->id]));
            });
    }
}
