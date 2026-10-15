<x-filament-panels::layout>
    <link rel="stylesheet" href="{{ asset('css/clubunio-workspace.css') }}?v=2">
    <div class="club-workspace">
        <nav class="workspace-navigation" aria-label="{{ match($module) { 'bus' => 'Busverwaltung', 'bexio' => 'Integrationen', default => 'Teamplanung' } }}">
            @if($module === 'bexio')
                <a href="{{ url('/settings') }}">Einstellungen</a>
                <a href="{{ route('clubunio.bexio.show') }}" aria-current="page">Bexio-Verbindung</a>
            @elseif($module === 'bus')
                <a href="{{ route('clubunio.bus.index', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.bus.index')) aria-current="page" @endif>Reservationen</a>
                <a href="{{ route('clubunio.bus.options', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.bus.options')) aria-current="page" @endif>Busse und Status</a>
            @else
                @foreach(['index' => 'Termine', 'calendar' => 'Teamkalender', 'absences' => 'Absenzen', 'sfv' => 'SFV-Spiele', 'options' => 'Auswahlwerte'] as $page => $label)
                    @continue($page === 'sfv' && !count($managed))
                    <a href="{{ route('clubunio.planning.'.$page, $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.planning.'.$page)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                @if(count($managed))<a href="{{ route('clubunio.training.index', $tenant->slug) }}" @if(request()->routeIs('clubunio.workspace.training.*')) aria-current="page" @endif>Trainingspläne</a>@endif
            @endif
        </nav>
        @if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
        @if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </div>
</x-filament-panels::layout>
