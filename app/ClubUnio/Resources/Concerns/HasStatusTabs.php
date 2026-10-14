<?php

namespace App\ClubUnio\Resources\Concerns;

use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

trait HasStatusTabs
{
    public function getTabs(): array
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        $model = static::getResource()::getModel();
        $query = $model::where('tenant_id', $tenant->id);
        $counts = (clone $query)->selectRaw('status_id, count(*) as total')->groupBy('status_id')->pluck('total', 'status_id');
        $statusModel = (new $model)->status()->getRelated();
        $tabs = ['all' => Tab::make('Alle ('.$counts->sum().')')];
        foreach ($statusModel->newQuery()->where('tenant_id', $tenant->id)->orderBy('sort_order')->get() as $status) {
            $tabs['status_'.$status->id] = Tab::make($status->name.' ('.($counts[$status->id] ?? 0).')')
                ->badgeColor(StatusAppearance::palette($status->name))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status_id', $status->id));
        }

        return $tabs;
    }
}
