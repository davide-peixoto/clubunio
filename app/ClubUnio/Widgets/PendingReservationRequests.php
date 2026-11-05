<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardReservationRequests;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class PendingReservationRequests extends Widget
{
    protected string $view = 'clubunio.widgets.pending-reservation-requests';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public int $busPage = 1;
    public int $pitchPage = 1;

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && (Filament::auth()->user()?->hasUnrestrictedBackofficeAccess($tenant) ?? false);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);

        return ['groups' => app(DashboardReservationRequests::class)->pending(Filament::auth()->user(), Filament::getTenant(), $this->busPage, $this->pitchPage)];
    }
}
