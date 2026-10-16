<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class BexioRecordActions
{
    private static function eligible($page, string $kind): bool
    {
        return BexioConnectionService::canManage(Filament::auth()->user()?->fresh(), Filament::getTenant())
            && DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', Filament::getTenant()->id)
                ->where('kind', $kind)->where($kind.'_id', $page->getRecord()->id)->where('state', 'accepted')->exists();
    }

    public static function status(string $kind): Action
    {
        return Action::make('bexioStatus')->label('Bexio-Status abgleichen')->icon('heroicon-o-arrow-path')
            ->visible(fn ($livewire) => self::eligible($livewire, $kind))
            ->requiresConfirmation()->modalDescription('Den aktuellen Bexio-Stand prüfen und bei unverändertem Nachweis in ClubUnio übernehmen.')
            ->action(function ($livewire) use ($kind) {
                try {
                    $id = app(SyncWorkService::class)->run(Filament::auth()->user(), Filament::getTenant(), $kind.'_sync', $livewire->getRecord()->id);
                    $state = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', Filament::getTenant()->id)->where('id', $id)->value('state');
                    Notification::make()->title(in_array($state, ['applied', 'unchanged'], true) ? 'Bexio-Stand abgeglichen' : 'Synchronisation prüfen')->send();
                    $resource = $kind === 'invoice' ? InvoiceResource::class : ExpenseResource::class;
                    $livewire->redirect($resource::getUrl('view', ['record' => $livewire->getRecord()->id]));
                } catch (\Throwable) {
                    Notification::make()->title('Abgleich offen')->body('Auftrag unter Synchronisation prüfen.')->warning()->send();
                }
            });
    }

    public static function invoice(string $operation): Action
    {
        return Action::make('bexio_'.$operation)->label($operation === 'issue' ? 'In Bexio freigeben' : 'In Bexio stornieren')
            ->icon($operation === 'issue' ? 'heroicon-o-check' : 'heroicon-o-x-mark')
            ->visible(fn ($livewire) => ($operation === 'issue' ? (string) $livewire->getRecord()->status?->code === '7'
                : in_array((string) $livewire->getRecord()->status?->code, ['8', '31'], true))
                && self::eligible($livewire, 'invoice'))
            ->requiresConfirmation()->modalDescription($operation === 'issue' ? 'Rechnung in der verbundenen Bexio-Organisation freigeben. Kein E-Mail-Versand.' : 'Unbezahlte Rechnung in Bexio stornieren. Belege mit Zahlungen oder Gutschriften bleiben gesperrt.')
            ->action(function ($livewire) use ($operation) {
                try {
                    app(BexioInvoiceActionService::class)->execute(Filament::auth()->user(), Filament::getTenant(), $livewire->getRecord()->id, $operation);
                    $accepted = DB::connection('clubunio')->table('clubora.bexio_invoice_actions as a')
                        ->join('clubora.bexio_sandbox_dispatches as d', 'd.id', '=', 'a.dispatch_id')
                        ->where('d.tenant_id', Filament::getTenant()->id)->where('d.invoice_id', $livewire->getRecord()->id)
                        ->where('a.operation', $operation)->where('a.state', 'accepted')->exists();
                    if (! $accepted) {
                        Notification::make()->title('Bexio-Aktion noch unbestätigt')->body('Auftrag unter Synchronisation prüfen.')->warning()->send();

                        return;
                    }
                    Notification::make()->title('Bexio-Aktion abgeschlossen')->success()->send();
                    $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $livewire->getRecord()->id]));
                } catch (\Throwable) {
                    Notification::make()->title('Bexio-Aktion nicht abgeschlossen')->body('Auftrag unter Synchronisation prüfen.')->warning()->send();
                }
            });
    }
}
