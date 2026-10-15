@extends('clubunio.portal.layout')
@section('content')
<h1>Materialübergaben</h1>
<section class="table-wrap"><table><thead><tr><th>Mitglied</th><th>Bezeichnung</th><th>Übergabe</th><th>Status</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td>{{ $receipt->member->display_name }}</td><td><a href="{{ route('clubunio.portal.material.show', [$tenant->slug, $receipt->id]) }}">{{ $receipt->title }}</a></td><td>{{ $receipt->handover_on->format('d.m.Y') }}</td><td>{{ $states[$receipt->state] ?? $receipt->state }}</td></tr>
@empty<tr><td colspan="4">Keine Materialübergaben vorhanden.</td></tr>@endforelse
</tbody></table></section>
{{ $receipts->links() }}
@if($history->isNotEmpty())
<h2>Historische Quittungen</h2>
<section class="table-wrap"><table><thead><tr><th>Mitglied</th><th>Titel</th><th>Historischer Status</th><th>Nachweis</th></tr></thead><tbody>
@foreach($history as $item)<tr><td>{{ $item->member->display_name }}</td><td>{{ $item->title }}</td><td>{{ $item->source_state }}</td><td><a href="{{ route('clubunio.portal.material.history.pdf', [$tenant->slug, $item->id]) }}" target="_blank" rel="noopener">PDF</a></td></tr>@endforeach
</tbody></table></section>{{ $history->links() }}
@endif
@endsection
