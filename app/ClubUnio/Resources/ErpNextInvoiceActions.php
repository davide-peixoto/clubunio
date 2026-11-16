<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\ErpNextInvoiceTransferService;
use App\ClubUnio\Services\ModulePermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErpNextInvoiceActions
{
    public static function transfer(callable $invoice): Action
    {
        return Action::make('exportErpNext')
            ->label(fn () => self::row($invoice()) ? trans('erpnext.retry') : trans('erpnext.send'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(function () use ($invoice) {
                [$actor, $tenant] = self::context();
                $record = $invoice();
                $row = self::row($record);

                return AccountingProvider::current($tenant) === 'erpnext'
                    && app(ModulePermissionService::class)->allows($actor, $tenant, 'invoices', 'send')
                    && ($row ? $row->state === 'pending' : $record->active && ! $record->legacy_id && ! $record->bexio_invoice_id
                        && $record->workflowStatus?->code === 'released');
            })
            ->requiresConfirmation()
            ->modalDescription(trans('erpnext.confirm'))
            ->action(fn () => self::run(fn ($actor, $tenant) => app(ErpNextInvoiceTransferService::class)
                ->transfer($actor, $tenant, $invoice()->id, $invoice()->revision)));
    }

    public static function status(callable $invoice): Action
    {
        return Action::make('erpNextStatus')
            ->label(fn () => trans('erpnext.status').': '.self::stateLabel(self::row($invoice())?->state))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => AccountingProvider::current(Filament::getTenant()) === 'erpnext'
                && self::row($invoice()) !== null)
            ->tooltip(trans('erpnext.refresh'))
            ->action(fn () => self::run(fn ($actor, $tenant) => app(ErpNextInvoiceTransferService::class)
                ->refresh($actor, $tenant, $invoice()->id)));
    }

    public static function remote(callable $invoice): Action
    {
        return Action::make('openErpNextInvoice')->label(trans('erpnext.open'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->visible(fn () => self::url($invoice()) !== null)
            ->url(fn () => self::url($invoice()), shouldOpenInNewTab: true);
    }

    private static function url(Invoice $invoice): ?string
    {
        $row = self::row($invoice);
        if (! $row?->remote_id || ! preg_match('/^https:\/\/[a-z0-9.-]+$/iD', (string) $row->origin)) {
            return null;
        }

        return $row->origin.'/desk/sales-invoice/'.rawurlencode($row->remote_id);
    }

    private static function row(Invoice $invoice): ?object
    {
        return ErpNextInvoiceTransferService::query(Filament::getTenant())->where('invoice_id', $invoice->id)->first();
    }

    private static function run(callable $operation): void
    {
        try {
            $row = $operation(...self::context());
        } catch (HttpExceptionInterface $e) {
            abort_if($e->getStatusCode() === 403 || $e->getStatusCode() === 404, $e->getStatusCode());
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }
        Notification::make()->title(self::stateLabel($row->state))->success()->send();
    }

    private static function stateLabel(?string $state): string
    {
        return $state ? trans('erpnext.states.'.$state) : '';
    }

    /** @return array{Account, Tenant} */
    private static function context(): array
    {
        return [Filament::auth()->user(), Filament::getTenant()];
    }
}
