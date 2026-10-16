<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\BexioBillingRecipientService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class BexioBillingRecipientAction
{
    public static function make(): Action
    {
        return Action::make('bexioBillingRecipient')->label('Bexio-Rechnungsempfänger')->icon('heroicon-o-link')
            ->visible(fn (Member $record) => ProfileUi::allows('bexio', 'update')
                && $record->archived_at === null && (int) $record->tenant_id === (int) Filament::getTenant()?->id
                && (Filament::auth()->user()?->can('view', $record) ?? false))
            ->modalHeading('Bexio-Rechnungsempfänger zuordnen')
            ->schema([
                Hidden::make('version'),
                TextInput::make('recipient')->label('Rechnung an')->readOnly()->dehydrated(false),
                TextInput::make('recipient_name')->label('Empfängername')->readOnly()->dehydrated(false),
                TextInput::make('recipient_email')->label('Empfänger-E-Mail')->readOnly()->dehydrated(false),
                TextInput::make('mapping_status')->label('Zuordnungsstatus')->readOnly()->dehydrated(false),
                TextInput::make('contact_id')->label('Bexio-Kontakt-ID (leer = Zuordnung entfernen)')->integer()->minValue(1)->maxValue(2147483647),
                Checkbox::make('confirmed')->label('Kontakt und Rechnungsempfänger geprüft; Änderung bestätigen')->accepted(),
            ])
            ->fillForm(function (Member $record) {
                $record = $record->fresh();
                $service = app(BexioBillingRecipientService::class);
                $identity = [];
                try {
                    $identity = $service->identity($record);
                    $service->resolve($record);
                    $status = $identity['field'] === 'email' ? 'Mitgliedskontakt' : 'Bestätigt';
                } catch (ValidationException $e) {
                    $status = 'Fehlt oder muss erneut bestätigt werden';
                }

                return ['version' => app(BexioBillingRecipientService::class)->version($record),
                    'recipient' => $record->billingRecipient?->name ?? 'Nicht zugeordnet',
                    'recipient_name' => $identity['name'] ?? '', 'recipient_email' => $identity['email'] ?? '', 'mapping_status' => $status,
                    'contact_id' => $record->billing_bexio_contact_id, 'confirmed' => false];
            })
            ->action(function (Member $record, array $data, $livewire) {
                FormMutation::run($livewire, fn () => app(BexioBillingRecipientService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data));
                Notification::make()->title('Rechnungsempfänger-Zuordnung gespeichert')->success()->send();
            });
    }
}
