@extends('clubunio.planning.layout')
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
@php($canEditGuests = $manager && $record->status === 'AKTIV' && $profileAllows('update'))
<div class="page-heading"><h1>{{ $record->title }}</h1>@include('clubunio.planning.badge',['category'=>'status','code'=>$record->status])@if($manager && $profileAllows('update'))<a class="icon-button" href="{{ route('clubunio.planning.edit',[$tenant->slug,$record->id]) }}" title="Termin bearbeiten" aria-label="Termin bearbeiten">@svg('heroicon-o-pencil-square')</a>@endif</div>
<dl class="planning-facts">
@if($manager && $record->series_key && $profileAllows('update'))<div><dt>Serie</dt><dd><a href="{{ route('clubunio.planning.series',[$tenant->slug,$record->id]) }}">Serie bearbeiten</a>@if($record->series_exception) · Einzelanpassung @endif</dd></div>@endif
<div><dt>Mannschaft</dt><dd>{{ $teams->firstWhere('id',$record->team_season_id)?->label }}</dd></div>
<div><dt>Beginn / Ende</dt><dd>{{ \Carbon\Carbon::parse($record->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }} / {{ \Carbon\Carbon::parse($record->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</dd></div>
<div><dt>Ort</dt><dd>{{ $record->location??'–' }}</dd></div>
<div><dt>An-/Abmeldefrist</dt><dd>{{ $record->deadline_at ? \Carbon\Carbon::parse($record->deadline_at)->tz('Europe/Zurich')->format('d.m.Y H:i') : 'Keine Frist' }}</dd></div>
<div><dt>Teilnahme</dt><dd>{{ $options['mode'][$record->mode]->name??$record->mode }} · {{ $record->capacity ? $record->capacity.' Plätze' : 'Unbegrenzte Plätze' }}</dd></div></dl>
@if($record->description)<p class="pre-line">{{ $record->description }}</p>@endif
@if($locked && !$manager)<p class="notice">Die Teilnahme ist gesperrt. Bitte das Trainerteam kontaktieren.</p>@endif
<h2>Teilnehmer</h2><div class="table-scroll"><table><thead><tr><th>Mitglied</th><th>Teilnahme</th><th>Anwesenheit</th><th>Bemerkung</th><th></th></tr></thead><tbody>
@foreach($roster as $member)
@php($part=$parts[$member->id]??null)
@php($status=$part->status??($record->mode==='ABMELDEN'?'DABEI':'OFFEN'))
@php($editable=$profileAllows('update') && $record->status==='AKTIV' && ($manager || (!$locked && in_array($member->id,$ownIds,true) && $status!=='NICHT_AUFGEBOT')))
<tr><td>{{ $member->display_name }}</td><td>
@if($editable)<form id="member-{{ $member->id }}" method="post" action="{{ route('clubunio.planning.participate',[$tenant->slug,$record->id,$member->id]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $part->revision??0 }}"></form>
<select form="member-{{ $member->id }}" name="status" aria-label="Teilnahme {{ $member->display_name }}">@foreach($options['participation']??[] as $option)@if($option->active && ($manager || in_array($option->code,['DABEI','ABWESEND'],true)))<option value="{{ $option->code }}" @selected($status===$option->code)>{{ $option->name }}</option>@endif @endforeach
@if(!$manager && $status==='OFFEN')<option value="" selected disabled>Bitte wählen</option>@endif</select>
@else @include('clubunio.planning.badge',['category'=>'participation','code'=>$status]) @endif
@if($part?->source==='absence')<small>Absenz</small>@endif</td><td>
@if($editable && $manager)<select name="attendance" form="member-{{ $member->id }}" aria-label="Anwesenheit {{ $member->display_name }}">@foreach($options['attendance']??[] as $option)<option value="{{ $option->code }}" @selected(($part->attendance??'OFFEN')===$option->code)>{{ $option->name }}</option>@endforeach</select>@else {{ $options['attendance'][$part->attendance??'OFFEN']->name??'Offen' }} @endif</td><td>
@if($editable)<input form="member-{{ $member->id }}" name="comment" maxlength="1000" value="{{ $part->comment??'' }}" aria-label="Bemerkung {{ $member->display_name }}">@else {{ $part->comment??'' }} @endif
</td><td>@if($editable)<button form="member-{{ $member->id }}" type="submit" class="icon-button" title="Teilnahme speichern" aria-label="Teilnahme speichern">@svg('heroicon-o-check')</button>@endif</td></tr>
@endforeach</tbody></table></div>
<h2>Gastspieler</h2>
<div class="table-scroll"><table><thead><tr><th>Vorname</th><th>Nachname</th><th>Teilnahme</th><th>Anwesenheit</th><th>Bemerkung</th><th></th></tr></thead><tbody>
@foreach($guests as $guest)
<tr><td>@if($canEditGuests)<form id="guest-{{ $guest->id }}" method="post" action="{{ route('clubunio.planning.guest',[$tenant->slug,$record->id,$guest->id]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $guest->revision }}"></form><input form="guest-{{ $guest->id }}" name="first_name" value="{{ $guest->first_name }}" required maxlength="100" aria-label="Vorname">@else {{ $guest->first_name }} @endif</td>
<td>@if($canEditGuests)<input form="guest-{{ $guest->id }}" name="last_name" value="{{ $guest->last_name }}" required maxlength="100" aria-label="Nachname">@else {{ $guest->last_name }} @endif</td>
@foreach(['status'=>'participation','attendance'=>'attendance'] as $field=>$category)<td>@if($canEditGuests)<select form="guest-{{ $guest->id }}" name="{{ $field }}" aria-label="{{ $category==='participation'?'Teilnahme':'Anwesenheit' }}">@foreach($options[$category] as $option)@if($option->active)<option value="{{ $option->code }}" @selected($guest->$field===$option->code)>{{ $option->name }}</option>@endif @endforeach</select>@else @include('clubunio.planning.badge',['category'=>$category,'code'=>$guest->$field]) @endif</td>@endforeach
<td>@if($canEditGuests)<input form="guest-{{ $guest->id }}" name="comment" maxlength="1000" value="{{ $guest->comment }}" aria-label="Bemerkung">@endif</td>
<td>@if($manager && $record->status==='AKTIV')<div class="actions">@if($profileAllows('update'))<button form="guest-{{ $guest->id }}" class="icon-button" title="Speichern" aria-label="Speichern">@svg('heroicon-o-check')</button>@endif @if($profileAllows('deactivate'))<form method="post" action="{{ route('clubunio.planning.guest.delete',[$tenant->slug,$record->id,$guest->id]) }}">@csrf @include('clubunio.workspace.context') @method('DELETE')<input type="hidden" name="revision" value="{{ $guest->revision }}"><button class="icon-button" title="Gast entfernen" aria-label="Gast entfernen">@svg('heroicon-o-trash')</button></form>@endif</div>@endif</td></tr>
@endforeach</tbody></table></div>
@if($manager && $record->status==='AKTIV')
@if($profileAllows('create'))<details><summary>Gastspieler hinzufügen</summary><form class="planning-form" method="post" action="{{ route('clubunio.planning.guest',[$tenant->slug,$record->id]) }}">@csrf @include('clubunio.workspace.context')
<label>Vorname<input name="first_name" required maxlength="100"></label><label>Nachname<input name="last_name" required maxlength="100"></label>
<label>Teilnahme<select name="status">@foreach($options['participation'] as $option)@if($option->active)<option value="{{ $option->code }}" @selected($option->code==='OFFEN')>{{ $option->name }}</option>@endif @endforeach</select></label>
<input type="hidden" name="attendance" value="OFFEN"><div class="wide"><button type="submit">@svg('heroicon-o-plus') Gast hinzufügen</button></div></form></details>@endif
@if($profileAllows('send'))<h2>Mitglied zusätzlich einladen</h2><form method="get" class="actions"><input name="member_search" minlength="2" maxlength="100" value="{{ $search }}" aria-label="Mitglied suchen" required><button title="Suchen" aria-label="Suchen" class="icon-button">@svg('heroicon-o-magnifying-glass')</button></form>
@if($search && $candidates->isEmpty())<p>Keine weiteren Mitglieder gefunden.</p>@endif
@foreach($candidates as $candidate)<form method="post" class="actions" action="{{ route('clubunio.planning.invite',[$tenant->slug,$record->id,$candidate->id]) }}">@csrf @include('clubunio.workspace.context')<span>{{ $candidate->first_name }} {{ $candidate->last_name }} · {{ $candidate->id }}</span><button class="icon-button" title="Einladen" aria-label="Einladen">@svg('heroicon-o-user-plus')</button></form>@endforeach @endif
@if($profileAllows('cancel'))@foreach($roster->whereIn('id',$invited) as $member)<form method="post" class="actions" action="{{ route('clubunio.planning.invite.delete',[$tenant->slug,$record->id,$member->id]) }}">@csrf @include('clubunio.workspace.context') @method('DELETE')<span>{{ $member->display_name }}</span><button title="Einladung entfernen" aria-label="Einladung entfernen" class="icon-button">@svg('heroicon-o-user-minus')</button></form>@endforeach @endif
@endif
@endsection
