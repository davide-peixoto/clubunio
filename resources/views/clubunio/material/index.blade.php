@extends('clubunio.portal.layout')
@section('content')
@php
    $materialActor = auth('portal')->user();
    $materialAccess = $materialActor && app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($materialActor, $tenant);
    $materialView = $materialAccess && \App\ClubUnio\Resources\ProfileUi::profileAllows($materialActor, $tenant, 'documents', 'view');
    $materialDownload = $materialAccess && \App\ClubUnio\Resources\ProfileUi::profileAllows($materialActor, $tenant, 'documents', 'download');
@endphp
<h1>Materialübergaben</h1>
<section class="table-wrap"><table><thead><tr><th>Mitglied</th><th>Bezeichnung</th><th>Übergabe</th><th>Status</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td>{{ $receipt->member->display_name }}</td><td>@if($materialView)<a href="{{ route('clubunio.portal.material.show', [$tenant->slug, $receipt->id]) }}">{{ $receipt->title }}</a>@else{{ $receipt->title }}@endif</td><td>{{ $receipt->handover_on->format('d.m.Y') }}</td><td>{{ $states[$receipt->state] ?? $receipt->state }}</td></tr>
@empty<tr><td colspan="4">Keine Materialübergaben vorhanden.</td></tr>@endforelse
</tbody></table></section>
{{ $receipts->links() }}
@if($history->isNotEmpty())
<h2>Historische Quittungen</h2>
<section class="table-wrap"><table><thead><tr><th>Mitglied</th><th>Titel</th><th>Historischer Status</th><th>Nachweis</th></tr></thead><tbody>
@foreach($history as $item)<tr><td>{{ $item->member->display_name }}</td><td>{{ $item->title }}</td><td>{{ $item->source_state }}</td><td>@if($materialDownload)<a href="{{ route('clubunio.portal.material.history.pdf', [$tenant->slug, $item->id]) }}" target="_blank" rel="noopener">PDF</a>@endif</td></tr>@endforeach
</tbody></table></section>{{ $history->links() }}
@endif
@endsection
