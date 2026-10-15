@extends('clubunio.planning.layout')
@section('content')
<h1>Absenzen</h1>
@if($own->count())<details @if($record || $errors->any()) open @endif><summary>{{ $record ? 'Absenz bearbeiten' : 'Absenz erfassen' }}</summary>
<form class="planning-form" method="post" action="{{ route('clubunio.planning.absence.save',[$tenant->slug,$record->id??null]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ old('revision',$record->revision??0) }}">
<label>Mitglied<select name="member_id" required>@foreach($own as $member)@if(!$record || $member->id===$record->member_id)<option value="{{ $member->id }}" @selected(old('member_id',$record->member_id??'')==$member->id)>{{ $member->display_name }}</option>@endif @endforeach</select></label>
<label>Art<select name="kind">@foreach($options['absence']??[] as $option)@if($option->active)<option value="{{ $option->code }}" @selected(old('kind',$record->kind??'ABWESEND')===$option->code)>{{ $option->name }}</option>@endif @endforeach</select></label>
<label>Von<input type="date" name="starts_on" required value="{{ old('starts_on',$record->starts_on??'') }}"></label><label>Bis<input type="date" name="ends_on" required value="{{ old('ends_on',$record->ends_on??'') }}"></label>
<label class="wide">Bemerkung<textarea name="note" maxlength="1000" rows="3">{{ old('note',$record->note??'') }}</textarea></label>
<div class="wide actions"><button type="submit" class="primary">@svg('heroicon-o-check') Speichern</button>@if($record)<a href="{{ route('clubunio.planning.absences',$tenant->slug) }}">Abbrechen</a>@endif</div></form></details>@endif
<div class="table-scroll"><table><thead><tr><th>Mitglied</th><th>Von</th><th>Bis</th><th>Art</th><th>Bemerkung</th><th></th></tr></thead><tbody>
@forelse($absences as $absence)<tr><td>{{ $members[$absence->member_id]->first_name??'' }} {{ $members[$absence->member_id]->last_name??'' }}</td><td>{{ \Carbon\Carbon::parse($absence->starts_on)->format('d.m.Y') }}</td><td>{{ \Carbon\Carbon::parse($absence->ends_on)->format('d.m.Y') }}</td><td>@include('clubunio.planning.badge',['category'=>'absence','code'=>$absence->kind])</td><td>{{ $absence->note }}</td><td>
@if($own->contains('id',$absence->member_id))<div class="actions"><a class="icon-button" href="{{ route('clubunio.planning.absences',[$tenant->slug,'edit'=>$absence->id]) }}" title="Bearbeiten" aria-label="Bearbeiten">@svg('heroicon-o-pencil-square')</a>
<form method="post" action="{{ route('clubunio.planning.absence.delete',[$tenant->slug,$absence->id]) }}">@csrf @include('clubunio.workspace.context') @method('DELETE')<input type="hidden" name="revision" value="{{ $absence->revision }}"><button class="icon-button" title="Absenz löschen" aria-label="Absenz löschen" onclick="return confirm('Diese Absenz löschen?')">@svg('heroicon-o-trash')</button></form></div>@endif</td></tr>
@empty<tr><td colspan="6">Keine Absenzen vorhanden.</td></tr>@endforelse</tbody></table></div>{{ $absences->links('pagination::simple-default') }}
@endsection
