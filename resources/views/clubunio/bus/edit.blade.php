@extends('clubunio.bus.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
<h1>{{ __('Reservation umbuchen') }}</h1>
<form method="post" class="planning-form" action="{{ route('clubunio.bus.reschedule',[$tenant->slug,$row->id]) }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="revision" value="{{ $row->revision }}">
<label>{{ __('Bus') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="bus_id" required>@foreach($buses->where('active',true) as $bus)<option value="{{ $bus->id }}" @selected(old('bus_id',$row->bus_id)==$bus->id)>{{ $bus->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@else<select name="bus_id" required>@foreach($buses->where('active',true) as $bus)<option value="{{ $bus->id }}" @selected(old('bus_id',$row->bus_id)==$bus->id)>{{ $bus->name }}</option>@endforeach</select>@endif</label>
<label>{{ __('Von') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="datetime-local" name="starts_at" required value="{{ old('starts_at',\Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}" /></x-filament::input.wrapper>@else<input type="datetime-local" name="starts_at" required value="{{ old('starts_at',\Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}">@endif</label>
<label>{{ __('Bis') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="datetime-local" name="ends_at" required value="{{ old('ends_at',\Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}" /></x-filament::input.wrapper>@else<input type="datetime-local" name="ends_at" required value="{{ old('ends_at',\Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}">@endif</label>
<label>{{ __('Fahrtziel') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="location" maxlength="255" value="{{ old('location',$row->location) }}" /></x-filament::input.wrapper>@else<input name="location" maxlength="255" value="{{ old('location',$row->location) }}">@endif</label>
<label class="wide">{{ __('Bemerkung') }}@if($workspaceComponents)<x-filament::input.wrapper><textarea class="fi-input" name="description" maxlength="1000">{{ old('description',$row->description) }}</textarea></x-filament::input.wrapper>@else<textarea name="description" maxlength="1000">{{ old('description',$row->description) }}</textarea>@endif</label>
<label class="wide">{{ __('Änderungsgrund') }}@if($workspaceComponents)<x-filament::input.wrapper><textarea class="fi-input" name="reason" required maxlength="1000">{{ old('reason') }}</textarea></x-filament::input.wrapper>@else<textarea name="reason" required maxlength="1000">{{ old('reason') }}</textarea>@endif</label>
<div class="actions wide">
@if($workspaceComponents)<x-filament::button icon="heroicon-o-check" type="submit" color="primary">{{ __('Umbuchung speichern') }}</x-filament::button>@else<button class="primary">@svg('heroicon-o-check') {{ __('Umbuchung speichern') }}</button>@endif<a href="{{ route('clubunio.bus.show',[$tenant->slug,$row->id]) }}">{{ __('Abbrechen') }}</a></div>
</form>
@endsection
