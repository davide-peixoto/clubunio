@extends('clubunio.portal.layout')
@section('content')
<a href="{{ route('clubunio.portal.material.index', $tenant->slug) }}">Zurück</a>
<h1>{{ $receipt->title }}</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<p>{{ $receipt->snapshot['member'] ?? $receipt->member->display_name }} · {{ $receipt->handover_on->format('d.m.Y') }} · {{ $states[$receipt->state] ?? $receipt->state }}</p>
<section class="table-wrap"><table><thead><tr><th>Gegenstand</th><th>Anzahl</th><th>Nummer / Grösse</th><th>Zustand</th></tr></thead><tbody>
@foreach($receipt->items as $item)<tr><td>{{ $item['description'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['reference'] ?? '' }}</td><td>{{ $item['condition'] ?? '' }}</td></tr>@endforeach
</tbody></table></section>
<p style="white-space:pre-wrap">{{ $receipt->note }}</p>
@if($receipt->state === 'issued')
<form method="post" action="{{ route('clubunio.portal.material.confirm', [$tenant->slug, $receipt->id]) }}">@csrf
<input type="hidden" name="revision" value="{{ $receipt->revision }}">
<label><input type="checkbox" name="accepted" value="1" required> {{ $receipt->snapshot['confirmation'] }}</label>
<button type="submit">Empfang bestätigen</button>
</form>
@elseif($receipt->confirmed_at)
<p>Bestätigt durch {{ $receipt->confirmation_name }} ({{ $receipt->confirmation_kind === 'parent' ? 'Elternvertretung' : 'Mitglied' }}) am {{ $receipt->confirmed_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }}.</p>
@elseif($receipt->state === 'cancelled')<p>Storniert: {{ $receipt->cancellation_reason }}</p>@endif
@if($receipt->snapshot)<p><a href="{{ route('clubunio.portal.material.pdf', [$tenant->slug, $receipt->id]) }}" target="_blank" rel="noopener">PDF-Quittung</a></p>@endif
@endsection
