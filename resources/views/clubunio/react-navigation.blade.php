@if(auth('clubunio')->check() && app(\App\ClubUnio\Services\ReactUi::class)->enabled() && is_file(public_path('js/clubunio-react-navigation.js')))
@php
    $items = [];
    $collect = function ($entries, $group) use (&$items, &$collect) {
        foreach ($entries as $item) {
            if (! $item->isVisible()) { continue; }
            if ($url = $item->getUrl()) { $items[] = ['label' => $item->getLabel(), 'url' => $url, 'group' => $group]; }
            $collect($item->getChildItems(), $group.' · '.$item->getLabel());
        }
    };
    foreach (\Filament\Facades\Filament::getNavigation() as $group) { $collect($group->getItems(), (string) $group->getLabel()); }
@endphp
<div data-cu-react-navigation data-props="{{ json_encode(['items' => $items, 'labels' => trans('react_ui')], JSON_THROW_ON_ERROR) }}"></div>
<script src="{{ asset('js/clubunio-react-navigation.js') }}?v=7" defer></script>
@endif
