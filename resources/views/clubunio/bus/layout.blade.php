@if(request()->attributes->get('clubunioWorkspace'))
@include('clubunio.workspace.layout', ['module' => 'bus'])
@else
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Busreservierungen · ClubUnio') }}</title><link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}"><link rel="stylesheet" href="{{ asset('css/clubunio-planning.css').'?v=blue-v1' }}">@include('clubunio.brand.head')</head>
<body><header class="portal-header">@include('clubunio.brand.logo')<nav aria-label="{{ __('Busreservierungen') }}">
<a class="icon-button" title="{{ __('Zur Übersicht') }}" aria-label="{{ __('Zur Übersicht') }}" href="{{ $actor->canAccessTenant($tenant) && auth('clubunio')->check() ? '/' : route('clubunio.portal.members',$tenant->slug) }}">@svg('heroicon-o-arrow-left')</a>
<a href="{{ route('clubunio.bus.index',$tenant->slug) }}">{{ __('Busreservierungen') }}</a>
@if($actor->canAccessTenant($tenant) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'view'))<a href="{{ route('clubunio.bus.options',$tenant->slug) }}">{{ __('Busse und Status') }}</a>@endif
</nav><span>{{ $actor->display_name }}</span></header><main>
@if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
@if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></body></html>
@endif
