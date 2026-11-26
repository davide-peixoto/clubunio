<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\{ActiveTenant, ModulePermissionService};
use Filament\Facades\Filament;
use Filament\Pages\Page;

final class SocialStudio extends Page
{
    protected string $view = 'clubunio.pages.social-studio';
    protected static ?string $slug = 'social-media';

    public static function getNavigationGroup(): ?string { return 'Anlagen & Kommunikation'; }
    public static function getNavigationIcon(): string { return 'heroicon-o-photo'; }
    public static function getNavigationLabel(): string { return trans('social.title'); }
    public function getTitle(): string { return trans('social.title'); }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('insights')->label(trans('social_insights.title'))
            ->icon('heroicon-o-chart-bar')->url(SocialInsights::getUrl())];
    }

    public static function canAccess(): bool
    {
        return config('clubunio-social.enabled') && ! request()->attributes->has('clubunio_impersonation')
            && Filament::auth()->user() && Filament::getTenant()
            && app(ModulePermissionService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'social_media', 'view');
    }

    public function studioConfig(): array
    {
        abort_unless(self::canAccess(), 403);
        return [
            'url' => route('clubunio.social.data', ['operation' => 'state'], false),
            'context' => session()->get(app(ActiveTenant::class)->key(request())),
            'csrf' => csrf_token(),
            'labels' => trans('social'),
            'module_ui' => app(\App\ClubUnio\Services\ReactUi::class)->enabled() ? trans('module_ui') : null,
            'locale' => app()->getLocale() === 'en' ? 'en-CH' : 'de-CH',
        ];
    }
}
