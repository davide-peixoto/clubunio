<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\TrainingSlot;
use App\ClubUnio\Resources\OpenInvoiceResource;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\MemberAccessService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClubOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant || ! Filament::auth()->user()?->canAccessTenant($tenant)) {
            return [];
        }

        $open = app(DashboardService::class)->openSummary(Filament::auth()->user(), $tenant);

        return [
            Stat::make('Offene Rechnungen', $open['count'])->icon('heroicon-o-document-text')
                ->url(OpenInvoiceResource::getUrl()),
            Stat::make('Offener Rechnungsbetrag', 'CHF '.number_format((float) $open['amount'], 2, '.', "'"))
                ->url(OpenInvoiceResource::getUrl()),
            Stat::make('Aktive Mitglieder', app(MemberAccessService::class)->activeMembers($tenant)->count())->icon('heroicon-o-users'),
            Stat::make('Anmeldungen in Prüfung', Registration::where('tenant_id', $tenant->id)->where('active', true)->whereHas('status', fn ($q) => $q->where('workflow_key', 'review'))->count())->icon('heroicon-o-user-plus'),
            Stat::make('Spesen zur Prüfung', Expense::where('tenant_id', $tenant->id)->where('active', true)->whereHas('status', fn ($q) => $q->where('code', 'F'))->count())->icon('heroicon-o-clipboard-document-check'),
            Stat::make('Spesen zur Zahlung', Expense::where('tenant_id', $tenant->id)->where('active', true)->whereHas('status', fn ($q) => $q->where('code', 'Z'))->count())->icon('heroicon-o-banknotes'),
            Stat::make('Aktive Trainingszeiten', TrainingSlot::where('tenant_id', $tenant->id)->where('active', true)->count())->icon('heroicon-o-clock'),
        ];
    }
}
