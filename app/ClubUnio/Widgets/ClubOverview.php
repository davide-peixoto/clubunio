<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Resources\CreditNoteResource;
use App\ClubUnio\Resources\MemberChangeRequestResource;
use App\ClubUnio\Resources\OpenInvoiceResource;
use App\ClubUnio\Resources\RefundResource;
use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClubOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return ($tenant = \Filament\Facades\Filament::getTenant()) && (\Filament\Facades\Filament::auth()->user()?->hasUnrestrictedBackofficeAccess($tenant) ?? false);
    }

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant || ! Filament::auth()->user()?->hasUnrestrictedBackofficeAccess($tenant)) {
            return [];
        }

        $counts = app(DashboardService::class)->overview(Filament::auth()->user(), $tenant);

        return [
            Stat::make(__('Offene Rechnungen'), $counts['open_count'])->icon('heroicon-o-document-text')
                ->url(OpenInvoiceResource::getUrl()),
            Stat::make(__('Offener Rechnungsbetrag'), 'CHF '.number_format((float) $counts['open_amount'], 2, '.', "'"))
                ->url(OpenInvoiceResource::getUrl()),
            Stat::make(__('Aktive Mitglieder'), $counts['members'])->icon('heroicon-o-users'),
            Stat::make(__('Mitgliederänderungsanträge'), $counts['changes'])
                ->icon('heroicon-o-pencil-square')->url(MemberChangeRequestResource::getUrl()),
            Stat::make(__('Anmeldungen in Prüfung'), $counts['registrations'])->icon('heroicon-o-user-plus'),
            Stat::make(__('Spesen zur Prüfung'), $counts['expenses_review'])->icon('heroicon-o-clipboard-document-check'),
            Stat::make(__('Spesen zur Zahlung'), $counts['expenses_payment'])->icon('heroicon-o-banknotes'),
            Stat::make(__('Aktive Trainingszeiten'), $counts['training_slots'])->icon('heroicon-o-clock'),
            Stat::make(__('Offene Gutschriften'), $counts['credits'])
                ->icon('heroicon-o-receipt-refund')->url(CreditNoteResource::getUrl()),
            Stat::make(__('Vorgesehene Rückerstattungen'), $counts['refunds'])
                ->icon('heroicon-o-arrow-uturn-left')->url(RefundResource::getUrl()),
            Stat::make(__('Verfügbares Guthaben'), 'CHF '.number_format($counts['refundable_cents'] / 100, 2, '.', "'"))
                ->icon('heroicon-o-banknotes')->url(RefundResource::getUrl()),
        ];
    }
}
