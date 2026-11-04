@extends('clubunio.planning.layout')
@if(request()->attributes->get('clubunioWorkspace'))
@section('native-content')
    @include('clubunio.planning.workspace-absence-form')
    @livewire('club-unio.planning-absences-table', ['tenantId' => (int) $tenant->id])
@endsection
@else
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>{{ __('Absenzen') }}</h1>
@if($absenceMembers->count() && $profileAllows($record ? 'update' : 'create'))<details @if($record || $errors->any()) open @endif><summary>{{ $record ? __('Absenz bearbeiten') : __('Absenz erfassen') }}</summary>
<form class="planning-form" method="post" action="{{ route('clubunio.planning.absence.save',[$tenant->slug,$record->id??null]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ old('revision',$record->revision??0) }}">
<label>{{ __('Mitglied') }}<select name="member_id" required>@foreach($absenceMembers as $member)@if(!$record || $member->id===$record->member_id)<option value="{{ $member->id }}" @selected(old('member_id',$record->member_id??'')==$member->id)>{{ $member->display_name }}</option>@endif @endforeach</select></label>
<label>{{ __('Art') }}<select name="kind">@foreach($options['absence']??[] as $option)@if($option->active)<option value="{{ $option->code }}" @selected(old('kind',$record->kind??'ABWESEND')===$option->code)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $option, tenant: $tenant->id ?? null) }}</option>@endif @endforeach</select></label>
<label>{{ __('Von') }}<input type="date" name="starts_on" required value="{{ old('starts_on',$record->starts_on??'') }}"></label><label>{{ __('Bis') }}<input type="date" name="ends_on" required value="{{ old('ends_on',$record->ends_on??'') }}"></label>
<label class="wide">{{ __('Bemerkung') }}<textarea name="note" maxlength="1000" rows="3">{{ old('note',$record->note??'') }}</textarea></label>
<div class="wide actions"><button type="submit" class="primary">@svg('heroicon-o-check') {{ __('Speichern') }}</button>@if($record)<a href="{{ route('clubunio.planning.absences',$tenant->slug) }}">{{ __('Abbrechen') }}</a>@endif</div></form></details>@endif
<div class="table-scroll"><table class="cu-admin-journal"><thead><tr><th>{{ __('Mitglied') }}</th><th>{{ __('Von') }}</th><th>{{ __('Bis') }}</th><th>{{ __('Art') }}</th><th>{{ __('Bemerkung') }}</th><th></th></tr></thead><tbody>
@forelse($absences as $absence)<tr><td>{{ $members[$absence->member_id]->first_name??'' }} {{ $members[$absence->member_id]->last_name??'' }}</td><td>{{ \Carbon\Carbon::parse($absence->starts_on)->format('d.m.Y') }}</td><td>{{ \Carbon\Carbon::parse($absence->ends_on)->format('d.m.Y') }}</td><td>@include('clubunio.planning.badge',['category'=>'absence','code'=>$absence->kind])</td><td>{{ $absence->note }}</td><td>
<div class="actions">@if($profileAllows('update') && in_array($absence->member_id,$absenceEditableIds,true))<a class="icon-button" href="{{ route('clubunio.planning.absences',[$tenant->slug,'edit'=>$absence->id]) }}" title="{{ __('Bearbeiten') }}" aria-label="{{ __('Bearbeiten') }}">@svg('heroicon-o-pencil-square')</a>@endif
@if($own->contains('id',$absence->member_id) && $profileAllows('deactivate'))<form method="post" action="{{ route('clubunio.planning.absence.delete',[$tenant->slug,$absence->id]) }}">@csrf @include('clubunio.workspace.context') @method('DELETE')<input type="hidden" name="revision" value="{{ $absence->revision }}"><button class="icon-button" title="{{ __('Absenz löschen') }}" aria-label="{{ __('Absenz löschen') }}" onclick="return confirm(@js(__('Diese Absenz löschen?')))">@svg('heroicon-o-trash')</button></form>@endif</div></td></tr>
@empty<tr><td colspan="6">{{ __('Keine Absenzen vorhanden.') }}</td></tr>@endforelse</tbody></table></div>{{ $absences->links('pagination::simple-default') }}
@endsection
@endif
