@extends('clubunio.portal.layout')
@section('content')
@php
    $materialActor = auth('portal')->user();
    $materialAccess = $materialActor && app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($materialActor, $tenant);
    $materialView = $materialAccess && \App\ClubUnio\Resources\ProfileUi::profileAllows($materialActor, $tenant, 'documents', 'view');
    $materialDownload = $materialAccess && \App\ClubUnio\Resources\ProfileUi::profileAllows($materialActor, $tenant, 'documents', 'download');
@endphp
<div class="page-heading"><h1>{{ __('Materialübergaben') }}</h1></div>
<div class="cu-portal-list">
<div class="table-scroll"><table><thead><tr><th scope="col">{{ __('Bezeichnung') }}</th><th scope="col">{{ __('Mitglied') }}</th><th scope="col">{{ __('Übergabe') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td>@if($materialView)<a href="{{ route('clubunio.portal.material.show', [$tenant->slug, $receipt->id]) }}">{{ $receipt->title }}</a>@else{{ $receipt->title }}@endif</td><td>{{ $receipt->member->display_name }}</td><td>{{ $receipt->handover_on->format('d.m.Y') }}</td><td>@include('clubunio.material.state')</td></tr>
@empty
    @include('clubunio.portal.empty-row', ['columns' => 4, 'icon' => 'heroicon-o-cube', 'message' => __('Keine Materialübergaben vorhanden.')])
@endforelse
</tbody></table></div>
{{ $receipts->links('clubunio.planning.pagination') }}
</div>
@if($history->isNotEmpty())
<section class="cu-portal-section">
<h2>{{ __('Historische Quittungen') }}</h2>
<div class="cu-portal-list">
<div class="table-scroll"><table><thead><tr><th scope="col">{{ __('Titel') }}</th><th scope="col">{{ __('Mitglied') }}</th><th scope="col">{{ __('Historischer Status') }}</th><th scope="col">{{ __('Nachweis') }}</th></tr></thead><tbody>
@foreach($history as $item)<tr><td>{{ $item->title }}</td><td>{{ $item->member->display_name }}</td><td>{{ $item->source_state }}</td><td>@if($materialDownload)<a class="cu-portal-secondary" href="{{ route('clubunio.portal.material.history.pdf', [$tenant->slug, $item->id]) }}" target="_blank" rel="noopener">@svg('heroicon-o-arrow-down-tray') {{ __('PDF') }}</a>@endif</td></tr>@endforeach
</tbody></table></div>{{ $history->links('clubunio.planning.pagination') }}
</div>
</section>
@endif
@endsection
