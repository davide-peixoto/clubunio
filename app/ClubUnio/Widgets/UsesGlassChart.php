<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Pages\Dashboard;
use Illuminate\Contracts\View\View;

trait UsesGlassChart
{
    public function render(): View
    {
        if (! app(Dashboard::class)->glassSelected()) {
            return parent::render();
        }

        abort_unless(static::canView(), 403);

        return view('clubunio.widgets.glass-chart', [
            'chart' => [
                'heading' => $this->getHeading(),
                'type' => $this->getType(),
                'data' => $this->getCachedData(),
                'locale' => app()->getLocale() === 'de' ? 'de-CH' : 'en-CH',
                'empty' => trans('dashboard_design.empty'),
                'details' => trans('dashboard_design.details'),
                'total' => trans('dashboard_design.total'),
            ],
        ]);
    }
}
