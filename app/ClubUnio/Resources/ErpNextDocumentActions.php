<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\ErpNextExpenseTransferService;
use App\ClubUnio\Services\ErpNextInvoiceTransferService;
use App\ClubUnio\Services\ModulePermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErpNextDocumentActions
{
    public static function invoice(callable $invoice): array
    {
        return self::make($invoice, 'invoice', ErpNextInvoiceTransferService::class, 'send',
            fn (Invoice $record) => $record->active && ! $record->legacy_id && ! $record->bexio_invoice_id
                && $record->workflowStatus?->code === 'released');
    }

    public static function expense(callable $expense): array
    {
        return self::make($expense, 'expense', ErpNextExpenseTransferService::class, 'approve',
            fn (Expense $record) => ! $record->bexio_bill_id && ErpNextExpenseTransferService::approved($record));
    }

    private static function make(callable $document, string $kind, string $service, string $permission, callable $ready): array
    {
        $row = fn () => $service::query(Filament::getTenant())->where($kind.'_id', $document()->id)->first();
        $enabled = fn () => AccountingProvider::current(Filament::getTenant()) === AccountingProvider::ERPNEXT;
        $route = $kind === 'invoice' ? 'sales-invoice' : 'purchase-invoice';

        return [
            Action::make('exportErpNext')
                ->label(fn () => $row() ? trans('erpnext.retry') : trans('erpnext.send'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(function () use ($document, $kind, $enabled, $permission, $row, $ready) {
                    [$actor, $tenant] = self::context();
                    if (! $enabled() || ! app(ModulePermissionService::class)->allows($actor, $tenant, $kind.'s', $permission)) {
                        return false;
                    }
                    $transfer = $row();

                    return $transfer ? $transfer->state === 'pending' : $ready($document());
                })
                ->requiresConfirmation()
                ->modalDescription($kind === 'invoice' ? trans('erpnext.confirm') : trans('erpnext.expense_confirm'))
                ->action(fn () => self::run(fn ($actor, $tenant) => app($service)
                    ->transfer($actor, $tenant, $document()->id, $document()->revision))),
            Action::make('erpNextStatus')
                ->label(fn () => self::statusLabel($row()))
                ->color(fn () => ($row()?->status_sync_failed ?? false) ? 'warning' : 'gray')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => $enabled() && $row() !== null)
                ->tooltip(fn () => ($row()?->status_sync_failed ?? false) ? trans('erpnext.errors.refresh') : trans('erpnext.refresh'))
                ->action(fn () => self::run(fn ($actor, $tenant) => app($service)->refresh($actor, $tenant, $document()->id))),
            Action::make('openErpNextInvoice')->label(trans('erpnext.open'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn () => self::url($row(), $route) !== null)
                ->url(fn () => self::url($row(), $route), shouldOpenInNewTab: true),
        ];
    }

    private static function url(?object $row, string $route): ?string
    {
        if (! $row?->remote_id || ! preg_match('/^https:\/\/[a-z0-9.-]+$/iD', (string) $row->origin)) {
            return null;
        }

        return $row->origin.'/desk/'.$route.'/'.rawurlencode($row->remote_id);
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

    private static function statusLabel(?object $row): string
    {
        if ($row?->status_sync_failed ?? false) {
            return trans('erpnext.status_stale', ['state' => self::stateLabel($row->state)]);
        }

        return trans('erpnext.status').': '.self::stateLabel($row?->state);
    }

    /** @return array{Account, Tenant} */
    private static function context(): array
    {
        return [Filament::auth()->user(), Filament::getTenant()];
    }
}
