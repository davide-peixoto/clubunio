@extends('clubunio.bus.layout')
@section('content')
<h1>Busse und Status</h1>
@foreach(['bus'=>['Busse',$buses],'status'=>['Reservierungsstatus',$statuses]] as $kind=>[$heading,$items])
<section><h2>{{ $heading }}</h2>
@foreach($items as $item)
<form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf
<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="id" value="{{ $item->id }}"><input type="hidden" name="version" value="{{ \App\ClubUnio\Services\BusReservationService::optionVersion($item) }}">
<label>Bezeichnung<input name="name" value="{{ $item->name }}" required maxlength="160"></label>
@if($kind==='status')<span>{{ $item->code }}</span>@endif
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($item->active)> Aktiv</label>
<button class="icon-button" title="Speichern" aria-label="{{ $item->name }} speichern">@svg('heroicon-o-check')</button>
</form>
@endforeach</section>
@endforeach
<section><h2>Neuer Bus</h2><form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf<input type="hidden" name="kind" value="bus"><input type="hidden" name="active" value="1"><label>Bezeichnung<input name="name" required maxlength="160" value=""></label><button class="primary">@svg('heroicon-o-plus') Bus hinzufügen</button></form></section>
@endsection
