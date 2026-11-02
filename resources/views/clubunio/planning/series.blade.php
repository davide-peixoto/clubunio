@extends('clubunio.planning.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>Serie bearbeiten</h1><h2>{{ $record->title }}</h2>
@if($profileAllows('update'))<form method="post" class="planning-form">@csrf @include('clubunio.workspace.context')
<label>Termine ab
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="date" name="from" required value="{{ old('from',$preview['input']['from']??now('Europe/Zurich')->toDateString()) }}" /></x-filament::input.wrapper>@else<input type="date" name="from" required value="{{ old('from',$preview['input']['from']??now('Europe/Zurich')->toDateString()) }}">@endif</label>
<label>Verschiebung in Minuten
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="shift_minutes" min="-1440" max="1440" required value="{{ old('shift_minutes',$preview['input']['shift_minutes']??0) }}" /></x-filament::input.wrapper>@else<input type="number" name="shift_minutes" min="-1440" max="1440" required value="{{ old('shift_minutes',$preview['input']['shift_minutes']??0) }}">@endif</label>
<label><input type="checkbox" name="change_location" value="1" @checked($preview['input']['change_location']??false)> Ort ändern</label>
<label>Neuer Ort
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="location" maxlength="255" value="{{ old('location',$preview['input']['location']??$record->location) }}" /></x-filament::input.wrapper>@else<input name="location" maxlength="255" value="{{ old('location',$preview['input']['location']??$record->location) }}">@endif</label>
<label><input type="checkbox" name="change_title" value="1" @checked($preview['input']['change_title']??false)> Titel ändern</label><label>Neuer Titel
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="title" maxlength="255" value="{{ old('title',$preview['input']['title']??$record->title) }}" /></x-filament::input.wrapper>@else<input name="title" maxlength="255" value="{{ old('title',$preview['input']['title']??$record->title) }}">@endif</label>
<label><input type="checkbox" name="change_capacity" value="1" @checked($preview['input']['change_capacity']??false)> Kapazität ändern</label><label>Maximale Teilnehmerzahl
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="capacity" min="1" max="1000" value="{{ old('capacity',$preview['input']['capacity']??$record->capacity) }}" /></x-filament::input.wrapper>@else<input type="number" name="capacity" min="1" max="1000" value="{{ old('capacity',$preview['input']['capacity']??$record->capacity) }}">@endif</label>
@if($profileAllows('cancel'))<label><input type="checkbox" name="cancel" value="1" @checked($preview['input']['cancel']??false)> Termine absagen</label>@endif
<div class="wide actions">
@if($workspaceComponents)<x-filament::button type="submit" color="gray">Vorschau</x-filament::button>@else<button type="submit">Vorschau</button>@endif</div></form>@endif
@if($preview)
<h2>{{ count($preview['changes']) }} Änderungen</h2><p>{{ $preview['skipped'] }} nicht betroffene oder geschützte Termine bleiben unverändert.</p>
<div class="table-scroll"><table class="cu-admin-journal"><thead><tr><th>Bisher</th><th>Titel</th><th>Neuer Beginn</th><th>Neue Frist</th><th>Ort</th><th>Plätze</th><th>Status</th></tr></thead><tbody>
@foreach($preview['changes'] as $change)<tr><td>{{ \Carbon\Carbon::parse($change['before']->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $change['input']['title'] }}</td><td>{{ str_replace('T',' ',$change['input']['starts_at']) }}</td><td>{{ str_replace('T',' ',$change['input']['deadline_at']) }}</td><td>{{ $change['input']['location'] }}</td><td>{{ $change['input']['capacity']??'Unbegrenzt' }}</td><td>@include('clubunio.planning.badge',['category'=>'status','code'=>$change['input']['status']])</td></tr>@endforeach
</tbody></table></div>
@if(count($preview['changes']) && $profileAllows('update') && (empty($preview['input']['cancel']) || $profileAllows('cancel')))<form method="post" action="{{ route('clubunio.planning.series.apply',[$tenant->slug,$record->id]) }}">@csrf @include('clubunio.workspace.context')
@foreach($preview['input'] as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach

@if($workspaceComponents)<x-filament::button type="submit" color="primary">{{ count($preview['changes']) }} Änderungen bestätigen</x-filament::button>@else<button class="primary" type="submit">{{ count($preview['changes']) }} Änderungen bestätigen</button>@endif</form>@endif
@endif
@endsection
