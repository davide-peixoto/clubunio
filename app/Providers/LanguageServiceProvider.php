<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class LanguageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\ClubUnio\Services\LookupTranslations::class);
    }

    public function boot(): void
    {
        foreach ([\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Eloquent\Builder::class] as $builder) {
            $builder::macro('localizedPluck', function ($column, $key = null) {
                return app(\App\ClubUnio\Services\LookupTranslations::class)->localizedPluck($this, $column, $key);
            });
        }
        foreach ([\Filament\Tables\Columns\TextColumn::class, \Filament\Infolists\Components\TextEntry::class] as $component) {
            $component::configureUsing(function ($instance) {
                if ($instance->getName() !== 'name' && ! str_ends_with($instance->getName(), '.name')) {
                    return;
                }
                $instance->formatStateUsing(function ($state, $record, ?\Illuminate\Database\Eloquent\Model $relatedRecord = null) use ($instance) {
                    return app(\App\ClubUnio\Services\LookupTranslations::class)->formatDisplay($instance->getName(), $state,
                        $record instanceof \Illuminate\Database\Eloquent\Model ? $record : null, $relatedRecord);
                });
            });
        }
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
