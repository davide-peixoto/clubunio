<x-filament-panels::layout>
    <link rel="stylesheet" href="{{ asset('css/clubunio-workspace.css') }}?v=4">
    <div class="club-workspace">
        <x-filament::tabs class="workspace-navigation" :label="match($module) { 'bus' => __('Busverwaltung'), 'bexio' => __('Integrationen'), default => __('Teamplanung') }">
            @if($module === 'bexio')
                <x-filament::tabs.item tag="a" :href="url('/settings')">{{ __('Einstellungen') }}</x-filament::tabs.item>
                @if(\App\ClubUnio\Services\BexioConnectionService::canManage(auth('clubunio')->user(), $tenant))<x-filament::tabs.item tag="a" :href="route('clubunio.bexio.show')" :active="true">{{ __('Bexio-Verbindung') }}</x-filament::tabs.item>@endif
            @elseif($module === 'bus')
                <x-filament::tabs.item tag="a" :href="route('clubunio.bus.index', $tenant->slug)" :active="!request()->routeIs('clubunio.workspace.bus.options')">{{ __('Reservationen') }}</x-filament::tabs.item>
                @if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'view'))<x-filament::tabs.item tag="a" :href="route('clubunio.bus.options', $tenant->slug)" :active="request()->routeIs('clubunio.workspace.bus.options')">{{ __('Busse und Status') }}</x-filament::tabs.item>@endif
            @else
                @foreach(['index' => __('Termine'), 'calendar' => __('Teamkalender'), 'absences' => __('Absenzen'), 'sfv' => __('SFV-Spiele'), 'options' => __('Auswahlwerte')] as $page => $label)
                    @continue(!\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', 'view'))
                    @continue($page === 'sfv' && !count($managed))
                    <x-filament::tabs.item tag="a" :href="route('clubunio.planning.'.$page, $tenant->slug)" :active="request()->routeIs('clubunio.workspace.planning.'.$page) || ($page === 'index' && request()->routeIs('clubunio.workspace.planning.show', 'clubunio.workspace.planning.create', 'clubunio.workspace.planning.edit', 'clubunio.workspace.planning.series'))">{{ $label }}</x-filament::tabs.item>
                @endforeach
                @if((count($managed) || app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'training', 'view')) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'view'))<x-filament::tabs.item tag="a" :href="route('clubunio.training.index', $tenant->slug)" :active="request()->routeIs('clubunio.workspace.training.*')">{{ __('Trainingspläne') }}</x-filament::tabs.item>@endif
            @endif
        </x-filament::tabs>
        @if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
        @if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @hasSection('native-content')
            <div class="cu-admin-native">@yield('native-content')</div>
        @else
            <div class="workspace-content">@yield('content')</div>
        @endif
    </div>
</x-filament-panels::layout>
