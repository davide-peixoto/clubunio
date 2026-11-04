@if(request()->attributes->get('clubunioWorkspace'))
@include('clubunio.workspace.layout', ['module' => 'planning'])
@else
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Teamplanung · ClubUnio') }}</title><link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}"><link rel="stylesheet" href="{{ asset('css/clubunio-planning.css').'?v=blue-v1' }}">@include('clubunio.brand.head')</head>
<body><header class="portal-header">@include('clubunio.brand.logo')<nav aria-label="{{ __('Teamplanung') }}">
<a href="{{ $actor->canAccessTenant($tenant) && auth('clubunio')->check() ? '/' : route('clubunio.portal.members',$tenant->slug) }}" class="icon-button" title="{{ __('Zur Übersicht') }}" aria-label="{{ __('Zur Übersicht') }}">@svg('heroicon-o-arrow-left')</a>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', 'view'))<a href="{{ route('clubunio.planning.index',$tenant->slug) }}">{{ __('Teamplanung') }}</a><a href="{{ route('clubunio.planning.absences',$tenant->slug) }}">{{ __('Absenzen') }}</a>
<a href="{{ route('clubunio.planning.calendar',$tenant->slug) }}">{{ __('Kalender') }}</a>@endif
@if(count($managed) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', 'view'))<a href="{{ route('clubunio.planning.sfv',$tenant->slug) }}">{{ __('SFV-Spiele') }}</a>@endif
@if((count($managed) || app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'training', 'view')) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'view'))<a href="{{ route('clubunio.training.index',$tenant->slug) }}">{{ __('Trainingspläne') }}</a>@endif
@if($actor->canAccessTenant($tenant) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', 'view'))<a href="{{ route('clubunio.planning.options',$tenant->slug) }}">{{ __('Auswahlwerte') }}</a>@endif
</nav><span>{{ $actor->display_name }}</span></header><main>
@if(session('notice'))<div role="status" class="notice">{{ session('notice') }}</div>@endif
@if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></body></html>
@endif
