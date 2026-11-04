@php
    $actor = auth('clubunio')->user();
    $active = \Filament\Facades\Filament::getTenant();
    $boundTenant = app(\App\ClubUnio\Services\TenantDomainService::class)->current();
    $tenants = $boundTenant ? collect([$boundTenant]) : ($actor?->getTenants(\Filament\Facades\Filament::getPanel('clubunio')) ?? collect());
    $context = $actor ? session(app(\App\ClubUnio\Services\ActiveTenant::class)->key(request())) : null;
@endphp
@if($active && $context)
    @if(count($tenants) > 1)
    <form method="post" action="{{ route('clubunio.tenant.switch') }}" class="px-4 py-2">
        @csrf
        <input type="hidden" name="revision" value="{{ $context['revision'] }}">
        <label for="active-club" class="sr-only">{{ __('Verein') }}</label>
        <select id="active-club" name="tenant" onchange="this.form.requestSubmit()" class="w-full rounded border border-gray-300 p-2 text-sm dark:bg-gray-900">
            @foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($tenant->id === $active->id)>{{ $tenant->name }}</option>@endforeach
        </select>
        <noscript><button type="submit">{{ __('Verein wechseln') }}</button></noscript>
    </form>
    @else
    <div class="px-4 py-2 text-sm">{{ $active->name }}</div>
    @endif
@endif
@if($actor)
@if(!$boundTenant && app(\App\ClubUnio\Services\PlatformAdministrationService::class)->allowed($actor))
<a href="{{ route('clubunio.platform.index') }}" class="cu-platform-link">@svg('heroicon-o-building-office-2','cu-platform-link-icon') <span>{{ __('Plattformverwaltung') }}</span></a>
@endif
<div class="cu-nav-controls" data-cu-navigation data-user="{{ $actor->id }}">
    <x-filament::icon-button icon="heroicon-o-magnifying-glass" :label="__('Menü durchsuchen')"
        :title="__('Menü durchsuchen')" data-cu-search-toggle aria-expanded="false" aria-controls="cu-menu-search-panel" />
    <div id="cu-menu-search-panel" hidden>
        <label for="cu-menu-search" class="cu-visually-hidden">{{ __('Menü durchsuchen') }}</label>
        <input id="cu-menu-search" type="search" placeholder="{{ __('Menü durchsuchen') }}" autocomplete="off">
    </div>
    <p class="cu-nav-empty" role="status" hidden>{{ __('Keine Menüpunkte gefunden.') }}</p>
    @if(\App\ClubUnio\Pages\SettingsOverview::canAccess())
    <a class="cu-settings-shortcut" data-settings-url="{{ \App\ClubUnio\Pages\SettingsOverview::getUrl() }}" href="{{ \App\ClubUnio\Pages\SettingsOverview::getUrl() }}" hidden>{{ __('In Einstellungen suchen') }}</a>
    @endif
</div>
@endif
