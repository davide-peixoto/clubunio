<?php

namespace App\ClubUnio\Services;

use Filament\Facades\Filament;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

/** Keep the permission snapshot inside rendering, never property updates or actions. */
class BackofficeHandleComponents extends HandleComponents
{
    public function mount($name, $params = [], $key = null, $slots = [])
    {
        return app(BackofficeReadContext::class)->withoutSnapshot(
            fn () => parent::mount($name, $params, $key, $slots)
        );
    }

    public function update($snapshot, $updates, $calls)
    {
        return app(BackofficeReadContext::class)->withoutSnapshot(
            fn () => parent::update($snapshot, $updates, $calls)
        );
    }

    protected function render($component, $default = null)
    {
        $render = fn () => parent::render($component, $default);
        if (Filament::getCurrentPanel()?->getId() !== 'clubunio' || ! Filament::getTenant()) {
            return $render();
        }

        return app(BackofficeReadContext::class)->duringRender($render);
    }
}
