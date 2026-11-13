<x-filament-panels::layout>
<div class="cu-admin-native cu-support">
<header class="fi-header"><h1 class="fi-header-heading">{{ __('Benutzersimulation') }}</h1></header>
@if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
<x-filament::section :heading="__('Simulation starten')">
@if($allowed)
<form class="cu-admin-form" method="post" action="{{ route('clubunio.support.start') }}">@csrf
<label>{{ __('Benutzer') }}<x-filament::input.wrapper><x-filament::input.select name="target_id" required><option value="">{{ __('Auswählen') }}</option>@foreach($accounts as $link)@if($link->role !== 'admin' && $link->account->active && !$link->account->api_only)<option value="{{ $link->account_id }}">{{ $link->account->display_name }} · {{ $link->account->username }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
<label>{{ __('Eigenes Passwort') }}<x-filament::input.wrapper><x-filament::input type="password" name="password" required autocomplete="current-password" /></x-filament::input.wrapper></label>
<label class="cu-admin-wide">{{ __('Supportgrund') }}<x-filament::input.wrapper><textarea class="fi-input" name="reason" required minlength="10" maxlength="500" rows="3"></textarea></x-filament::input.wrapper></label>
<div><x-filament::button type="submit" icon="heroicon-o-eye">{{ __('Nur-Lesen-Simulation starten') }}</x-filament::button></div></form>
@else<p>{{ __('Für dein Konto ist die Benutzersimulation nicht freigeschaltet.') }}</p>@endif
</x-filament::section>
<x-filament::section :heading="__('Persönliche Freischaltungen')">
@foreach($accounts as $link)@if($link->role === 'admin')
@php($grant = $grants->get($link->account_id))
<details class="cu-admin-disclosure"><summary>{{ $link->account->display_name }} <x-filament::badge :color="$grant?->enabled ? 'success' : 'gray'">{{ $grant?->enabled ? __('Freigeschaltet') : __('Gesperrt') }}</x-filament::badge></summary>
<form class="cu-admin-form" method="post" action="{{ route('clubunio.support.grant') }}">@csrf
<input type="hidden" name="target_id" value="{{ $link->account_id }}"><input type="hidden" name="revision" value="{{ $grant?->revision ?? 0 }}">
<label>{{ __('Freischaltung') }}<x-filament::input.wrapper><x-filament::input.select name="enabled"><option value="0" @selected(!$grant?->enabled)>{{ __('Gesperrt') }}</option><option value="1" @selected($grant?->enabled)>{{ __('Erlaubt') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
<label>{{ __('Eigenes Passwort') }}<x-filament::input.wrapper><x-filament::input type="password" name="password" required autocomplete="current-password" /></x-filament::input.wrapper></label>
<label class="cu-admin-wide">{{ __('Begründung') }}<x-filament::input.wrapper><textarea class="fi-input" name="reason" required minlength="10" maxlength="500" rows="3"></textarea></x-filament::input.wrapper></label>
<div><x-filament::button type="submit" color="gray" icon="heroicon-o-check">{{ __('Freischaltung speichern') }}</x-filament::button></div></form></details>
@endif @endforeach
</x-filament::section>
<x-filament::section :heading="__('Supportprotokoll')"><div class="cu-admin-journal"><table><thead><tr><th scope="col">{{ __('Zeit') }}</th><th scope="col">{{ __('Admin-ID') }}</th><th scope="col">{{ __('Benutzer-ID') }}</th><th scope="col">{{ __('Ereignis') }}</th><th scope="col">{{ __('Details') }}</th></tr></thead><tbody>
@foreach($events as $event)<tr><td>{{ \Carbon\CarbonImmutable::parse($event->created_at)->timezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</td><td>{{ $event->actor_id }}</td><td>{{ $event->target_id }}</td><td>{{ $event->action }}</td><td>{{ $event->details }}</td></tr>@endforeach
</tbody></table></div></x-filament::section>
</div>
</x-filament-panels::layout>
