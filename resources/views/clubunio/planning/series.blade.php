@extends('clubunio.planning.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>{{ __('Serie bearbeiten') }}</h1><h2>{{ $record->title }}</h2>
@if($profileAllows('update'))<form method="post" class="planning-form">@csrf @include('clubunio.workspace.context')
<label>{{ __('Termine ab') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="date" name="from" required value="{{ old('from',$preview['input']['from']??now('Europe/Zurich')->toDateString()) }}" /></x-filament::input.wrapper>@else<input type="date" name="from" required value="{{ old('from',$preview['input']['from']??now('Europe/Zurich')->toDateString()) }}">@endif</label>
<label>{{ __('Verschiebung in Minuten') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="shift_minutes" min="-1440" max="1440" required value="{{ old('shift_minutes',$preview['input']['shift_minutes']??0) }}" /></x-filament::input.wrapper>@else<input type="number" name="shift_minutes" min="-1440" max="1440" required value="{{ old('shift_minutes',$preview['input']['shift_minutes']??0) }}">@endif</label>
<label>@if($workspaceComponents)<x-filament::input.checkbox name="change_location" value="1" :checked="$preview['input']['change_location']??false" />@else<input type="checkbox" name="change_location" value="1" @checked($preview['input']['change_location']??false)>@endif {{ __('Ort ändern') }}</label>
<label>{{ __('Neuer Ort') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="location" maxlength="255" value="{{ old('location',$preview['input']['location']??$record->location) }}" /></x-filament::input.wrapper>@else<input name="location" maxlength="255" value="{{ old('location',$preview['input']['location']??$record->location) }}">@endif</label>
<label>@if($workspaceComponents)<x-filament::input.checkbox name="change_title" value="1" :checked="$preview['input']['change_title']??false" />@else<input type="checkbox" name="change_title" value="1" @checked($preview['input']['change_title']??false)>@endif {{ __('Titel ändern') }}</label><label>{{ __('Neuer Titel') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="title" maxlength="255" value="{{ old('title',$preview['input']['title']??$record->title) }}" /></x-filament::input.wrapper>@else<input name="title" maxlength="255" value="{{ old('title',$preview['input']['title']??$record->title) }}">@endif</label>
<label>@if($workspaceComponents)<x-filament::input.checkbox name="change_capacity" value="1" :checked="$preview['input']['change_capacity']??false" />@else<input type="checkbox" name="change_capacity" value="1" @checked($preview['input']['change_capacity']??false)>@endif {{ __('Kapazität ändern') }}</label><label>{{ __('Maximale Teilnehmerzahl') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="capacity" min="1" max="1000" value="{{ old('capacity',$preview['input']['capacity']??$record->capacity) }}" /></x-filament::input.wrapper>@else<input type="number" name="capacity" min="1" max="1000" value="{{ old('capacity',$preview['input']['capacity']??$record->capacity) }}">@endif</label>
@if($profileAllows('cancel'))<label>@if($workspaceComponents)<x-filament::input.checkbox name="cancel" value="1" :checked="$preview['input']['cancel']??false" />@else<input type="checkbox" name="cancel" value="1" @checked($preview['input']['cancel']??false)>@endif {{ __('Termine absagen') }}</label>@endif
<div class="wide actions">
@if($workspaceComponents)<x-filament::button type="submit" color="gray">{{ __('Vorschau') }}</x-filament::button>@else<button type="submit">{{ __('Vorschau') }}</button>@endif</div></form>@endif
@if($preview)
<h2>{{ count($preview['changes']) }} {{ __('Änderungen') }}</h2><p>{{ $preview['skipped'] }} {{ __('nicht betroffene oder geschützte Termine bleiben unverändert.') }}</p>
<div class="table-scroll"><table class="cu-admin-journal"><thead><tr><th>{{ __('Bisher') }}</th><th>{{ __('Titel') }}</th><th>{{ __('Neuer Beginn') }}</th><th>{{ __('Neue Frist') }}</th><th>{{ __('Ort') }}</th><th>{{ __('Plätze') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@foreach($preview['changes'] as $change)<tr><td>{{ \Carbon\Carbon::parse($change['before']->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $change['input']['title'] }}</td><td>{{ str_replace('T',' ',$change['input']['starts_at']) }}</td><td>{{ str_replace('T',' ',$change['input']['deadline_at']) }}</td><td>{{ $change['input']['location'] }}</td><td>{{ $change['input']['capacity']??__('Unbegrenzt') }}</td><td>@include('clubunio.planning.badge',['category'=>'status','code'=>$change['input']['status']])</td></tr>@endforeach
</tbody></table></div>
@if(count($preview['changes']) && $profileAllows('update') && (empty($preview['input']['cancel']) || $profileAllows('cancel')))<form method="post" action="{{ route('clubunio.planning.series.apply',[$tenant->slug,$record->id]) }}">@csrf @include('clubunio.workspace.context')
@foreach($preview['input'] as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach

@if($workspaceComponents)<x-filament::button type="submit" color="primary">{{ count($preview['changes']) }} {{ __('Änderungen bestätigen') }}</x-filament::button>@else<button class="primary" type="submit">{{ count($preview['changes']) }} {{ __('Änderungen bestätigen') }}</button>@endif</form>@endif
@endif
@endsection
