<x-filament-panels::layout>
    <link rel="stylesheet" href="{{ asset('css/clubunio-workspace.css') }}?v=3">
    <div class="club-workspace">
        <nav class="workspace-navigation" aria-label="{{ match($module) { 'bus' => __('Busverwaltung'), 'bexio' => __('Integrationen'), default => __('Teamplanung') } }}">
            @if($module === 'bexio')
                <a href="{{ url('/settings') }}">{{ __('Einstellungen') }}</a>
                @if(\App\ClubUnio\Services\BexioConnectionService::canManage(auth('clubunio')->user(), $tenant))<a href="{{ route('clubunio.bexio.show') }}" aria-current="page">{{ __('Bexio-Verbindung') }}</a>@endif
            @elseif($module === 'bus')
                <a href="{{ route('clubunio.bus.index', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.bus.index')) aria-current="page" @endif>{{ __('Reservationen') }}</a>
                @if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'view'))<a href="{{ route('clubunio.bus.options', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.bus.options')) aria-current="page" @endif>{{ __('Busse und Status') }}</a>@endif
            @else
                @foreach(['index' => __('Termine'), 'calendar' => __('Teamkalender'), 'absences' => __('Absenzen'), 'sfv' => __('SFV-Spiele'), 'options' => __('Auswahlwerte')] as $page => $label)
                    @continue(!\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', 'view'))
                    @continue($page === 'sfv' && !count($managed))
                    <a href="{{ route('clubunio.planning.'.$page, $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.planning.'.$page)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                @if((count($managed) || app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'training', 'view')) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'view'))<a href="{{ route('clubunio.training.index', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.training.*')) aria-current="page" @endif>{{ __('Trainingspläne') }}</a>@endif
            @endif
        </nav>
        @if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
        @if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @hasSection('native-content')
            <div class="cu-admin-native">@yield('native-content')</div>
        @else
            <div class="workspace-content">@yield('content')</div>
        @endif
    </div>
</x-filament-panels::layout>
