<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamplanung · ClubUnio</title><link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}"><link rel="stylesheet" href="{{ asset('css/clubunio-planning.css') }}"></head>
<body><header class="portal-header"><strong>ClubUnio</strong><nav aria-label="Teamplanung">
<a href="{{ $actor->canAccessTenant($tenant) && auth('clubunio')->check() ? '/clubunio/'.$tenant->slug : route('clubunio.portal.members',$tenant->slug) }}" class="icon-button" title="Zur Übersicht" aria-label="Zur Übersicht">@svg('heroicon-o-arrow-left')</a>
<a href="{{ route('clubunio.planning.index',$tenant->slug) }}">Teamplanung</a><a href="{{ route('clubunio.planning.absences',$tenant->slug) }}">Absenzen</a>
<a href="{{ route('clubunio.planning.calendar',$tenant->slug) }}">Kalender</a>
@if(count($managed))<a href="{{ route('clubunio.planning.sfv',$tenant->slug) }}">SFV-Spiele</a>@endif
@if(count($managed))<a href="{{ route('clubunio.training.index',$tenant->slug) }}">Trainingspläne</a>@endif
@if($actor->canAccessTenant($tenant))<a href="{{ route('clubunio.planning.options',$tenant->slug) }}">Auswahlwerte</a>@endif
</nav><span>{{ $actor->display_name }}</span></header><main>
@if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
@if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></body></html>
