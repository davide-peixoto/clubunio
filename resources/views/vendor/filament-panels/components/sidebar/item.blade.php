@props([
    'active' => false, 'activeChildItems' => false, 'activeIcon' => null,
    'badge' => null, 'badgeColor' => null, 'badgeTooltip' => null, 'childItems' => [],
    'first' => false, 'grouped' => false, 'icon' => null, 'last' => false,
    'shouldOpenUrlInNewTab' => false, 'sidebarCollapsible' => true,
    'subGrouped' => false, 'subNavigation' => false, 'url',
])
@if($attributes->has('data-cu-submenu'))
    @php($submenuId = 'cu-submenu-'.$attributes->get('data-cu-submenu'))
    <li {{ $attributes->class(['fi-sidebar-item', 'fi-active' => $active, 'fi-sidebar-item-has-active-child-items' => $activeChildItems]) }}
        x-data="{ open: @js($active || $activeChildItems), beforeSearch: null }"
        x-on:cu-submenu-search="if ($event.detail.active) { if (beforeSearch === null) beforeSearch = open; open = true } else if (beforeSearch !== null) { open = beforeSearch; beforeSearch = null }">
        <div class="cu-submenu-row">
            @if(filled($url))
                <a {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab) }} class="fi-sidebar-item-btn"
                    @if($active) aria-current="page" @endif
                    x-on:click="window.matchMedia('(max-width: 1024px)').matches && $store.sidebar.close()">
                    <x-filament::icon :icon="$active && $activeIcon ? $activeIcon : $icon" class="fi-sidebar-item-icon" />
                    <span class="fi-sidebar-item-label">{{ $slot }}</span>
                </a>
            @else
                <span class="fi-sidebar-item-btn">
                    <x-filament::icon :icon="$icon" class="fi-sidebar-item-icon" />
                    <span class="fi-sidebar-item-label">{{ $slot }}</span>
                </span>
            @endif
            <button type="button" class="cu-submenu-toggle" aria-label="Untermenü {{ trim(strip_tags($slot->toHtml())) }}"
                aria-controls="{{ $submenuId }}" x-bind:aria-expanded="open.toString()" x-on:click="open = !open"
                title="Untermenü auf- oder zuklappen">
                <x-filament::icon icon="heroicon-m-chevron-down" class="cu-submenu-chevron" x-bind:class="{ 'cu-submenu-open': open }" />
            </button>
        </div>
        <ul id="{{ $submenuId }}" class="fi-sidebar-sub-group-items" x-show="open" x-cloak>
            @foreach($childItems as $childItem)
                <x-filament-panels::sidebar.item
                    :active="$childItem->isActive()" :active-icon="$childItem->getActiveIcon()"
                    :icon="$childItem->getIcon()" :badge="$childItem->getBadge()"
                    :badge-color="$childItem->getBadgeColor($childItem->getBadge())"
                    :badge-tooltip="$childItem->getBadgeTooltip($childItem->getBadge())"
                    :first="$loop->first" :last="$loop->last" grouped sub-grouped
                    :should-open-url-in-new-tab="$childItem->shouldOpenUrlInNewTab()"
                    :url="$childItem->getUrl()" :attributes="$childItem->getExtraAttributeBag()">
                    {{ $childItem->getLabel() }}
                </x-filament-panels::sidebar.item>
            @endforeach
        </ul>
    </li>
@else
    @include('clubunio-filament-original::components.sidebar.item')
@endif
