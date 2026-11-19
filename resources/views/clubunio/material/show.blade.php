@extends('clubunio.portal.layout')
@section('content')
@php
    $materialActor = auth('portal')->user();
    $materialAccess = app(\App\ClubUnio\Services\MemberAccessService::class);
    $materialOwned = $materialActor && $materialAccess->canEnter($materialActor, $tenant)
        && (int) $receipt->tenant_id === (int) $tenant->id && $receipt->state !== 'draft'
        && $materialAccess->represented($materialActor, $tenant)->whereKey($receipt->member_id)->exists();
    $materialDownload = $materialOwned && \App\ClubUnio\Resources\ProfileUi::profileAllows($materialActor, $tenant, 'documents', 'download');
@endphp
<div class="page-heading">
    <div><h1>{{ $receipt->title }}</h1><p>{{ $receipt->snapshot['member'] ?? $receipt->member->display_name }} · {{ $receipt->handover_on->format('d.m.Y') }}</p></div>
    <div class="cu-portal-actions">
        <a class="cu-portal-secondary" href="{{ route('clubunio.portal.material.index', $tenant->slug) }}">@svg('heroicon-o-arrow-left') {{ __('Materialübergaben') }}</a>
        @if($receipt->snapshot && $materialDownload)<a class="cu-portal-secondary" href="{{ route('clubunio.portal.material.pdf', [$tenant->slug, $receipt->id]) }}" target="_blank" rel="noopener">@svg('heroicon-o-arrow-down-tray') {{ __('PDF-Quittung') }}</a>@endif
    </div>
</div>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<div class="cu-portal-list">
<div class="cu-portal-list-bar">@include('clubunio.material.state')</div>
<div class="table-scroll"><table><thead><tr><th scope="col">{{ __('Gegenstand') }}</th><th scope="col">{{ __('Anzahl') }}</th><th scope="col">{{ __('Nummer / Grösse') }}</th><th scope="col">{{ __('Zustand') }}</th></tr></thead><tbody>
@foreach($receipt->items as $item)<tr><td>{{ $item['description'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['reference'] ?? '' }}</td><td>{{ $item['condition'] ?? '' }}</td></tr>@endforeach
</tbody></table></div>
</div>
@if($receipt->note)<p class="cu-portal-prose">{{ $receipt->note }}</p>@endif
<section class="cu-portal-form-section">
@if($receipt->state === 'issued' && $receipt->snapshot && $materialOwned)
<form class="registration-form" method="post" action="{{ route('clubunio.portal.material.confirm', [$tenant->slug, $receipt->id]) }}">@csrf
<input type="hidden" name="revision" value="{{ $receipt->revision }}">
<label class="full-width"><input type="checkbox" name="accepted" value="1" required> <span>{{ $receipt->snapshot['confirmation'] }}</span></label>
<button class="primary" type="submit">@svg('heroicon-o-check') {{ __('Empfang bestätigen') }}</button>
</form>
@elseif($receipt->confirmed_at)
<p>{{ __('Bestätigt durch') }} {{ $receipt->confirmation_name }} ({{ $receipt->confirmation_kind === 'parent' ? __('Elternvertretung') : __('Mitglied') }}{{ __(') am') }} {{ $receipt->confirmed_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }}.</p>
@elseif($receipt->state === 'cancelled')<p>{{ __('Storniert:') }} {{ $receipt->cancellation_reason }}</p>@endif
</section>
@endsection
