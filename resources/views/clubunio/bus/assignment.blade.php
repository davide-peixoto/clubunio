@extends('clubunio.bus.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
<h1>{{ __('Historische Zuordnung') }}</h1>
<form method="post" action="{{ route('clubunio.bus.assign',[$tenant->slug,$row->id]) }}" class="planning-form">
@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}">
<label>{{ __('Mannschaft / Saison') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="team_season_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id',$row->team_season_id) == $team->id) @disabled($row->team_season_id && $row->team_season_id != $team->id)>{{ $team->label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@else<select name="team_season_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id',$row->team_season_id) == $team->id) @disabled($row->team_season_id && $row->team_season_id != $team->id)>{{ $team->label }}</option>@endforeach</select>@endif</label>
<label>{{ __('Antragsteller') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="member_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected(old('member_id',$row->member_id) == $member->id) @disabled($row->member_id && $row->member_id != $member->id)>{{ $member->last_name }} {{ $member->first_name }} ({{ $member->legacy_id ?? $member->id }})</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@else<select name="member_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected(old('member_id',$row->member_id) == $member->id) @disabled($row->member_id && $row->member_id != $member->id)>{{ $member->last_name }} {{ $member->first_name }} ({{ $member->legacy_id ?? $member->id }})</option>@endforeach</select>@endif</label>
<label class="wide">{{ __('Begründung') }}@if($workspaceComponents)<x-filament::input.wrapper><textarea class="fi-input" name="reason" minlength="3" maxlength="1000" required>{{ old('reason') }}</textarea></x-filament::input.wrapper>@else<textarea name="reason" minlength="3" maxlength="1000" required>{{ old('reason') }}</textarea>@endif</label>

@if($workspaceComponents)<x-filament::button icon="heroicon-o-check" type="submit" color="primary">{{ __('Zuordnung speichern') }}</x-filament::button>@else<button class="primary">@svg('heroicon-o-check') {{ __('Zuordnung speichern') }}</button>@endif
</form>
@endsection
