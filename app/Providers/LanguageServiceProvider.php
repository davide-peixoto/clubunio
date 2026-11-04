<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class LanguageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app('translator')->setFallback(config('clubunio-languages.default', 'de'));
        foreach ([\Filament\Actions\Action::class, \Filament\Schemas\Components\Component::class,
            \Filament\Tables\Columns\Column::class, \Filament\Tables\Filters\BaseFilter::class] as $component) {
            $component::configureUsing(function ($instance) {
                if (method_exists($instance, 'translateLabel')) { $instance->translateLabel(); }
            });
        }
        \Livewire\Livewire::addPersistentMiddleware([\App\ClubUnio\Http\UseLanguage::class]);
    }
}
