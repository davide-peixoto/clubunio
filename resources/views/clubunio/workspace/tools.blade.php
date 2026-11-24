@if(auth('clubunio')->check() && \App\ClubUnio\Pages\MyClub::canAccess())
@php
    app(\App\ClubUnio\Services\WorkspacePreferences::class)->rememberCurrent();
    $workspaceQuick = app(\App\ClubUnio\Services\WorkspaceLinks::class)->quickActions();
@endphp
<link rel="stylesheet" href="{{ asset('css/clubunio-workflows.css') }}?v=1">
<nav class="cu-mobile-quick" aria-label="{{ trans('workspace.quick') }}"><a href="{{ \App\ClubUnio\Pages\MyClub::getUrl() }}"><x-filament::icon icon="heroicon-o-home" /><span>{{ trans('workspace.home') }}</span></a>@foreach($workspaceQuick as $item)<a href="{{ $item['url'] }}"><x-filament::icon :icon="$item['icon']" /><span>{{ $item['label'] }}</span></a>@endforeach</nav>
@endif
