@extends('clubunio.platform.layout')
@section('content')
<div class="platform-heading"><div><span class="platform-eyebrow">{{ __('Plattformverwaltung') }}</span><h1>{{ __('Vereine') }}</h1></div><a class="primary" href="#new-club">@svg('heroicon-o-plus') {{ __('Verein erstellen') }}</a></div>
<form method="get" class="platform-search"><label>{{ __('Verein suchen') }}<input type="search" name="q" value="{{ $q }}" maxlength="100"></label><button class="icon-button" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button>@if($q)<a href="{{ route('clubunio.platform.index') }}">{{ __('Zurücksetzen') }}</a>@endif</form>
<div class="table-scroll"><table><thead><tr><th>{{ __('Verein') }}</th><th>{{ __('Kennung') }}</th><th>{{ __('Benutzerzuordnungen') }}</th><th></th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><a href="{{ route('clubunio.platform.show',$row->id) }}">{{ $row->name }}</a></td><td>{{ $row->slug }}</td><td>{{ $row->tenant_accounts_count }}</td><td><a class="icon-button" href="{{ route('clubunio.platform.show',$row->id) }}" title="{{ __('Verein bearbeiten') }}" aria-label="{{ __(':name bearbeiten', ['name' => $row->name]) }}">@svg('heroicon-o-pencil-square')</a></td></tr>@empty<tr><td colspan="4">{{ __('Keine Vereine gefunden.') }}</td></tr>@endforelse
</tbody></table></div>{{ $rows->links('pagination::simple-default') }}
<section id="new-club" class="platform-section"><h2>{{ __('Neuer Verein') }}</h2><form method="post" action="{{ route('clubunio.platform.create') }}" class="platform-form">@csrf
<label>{{ __('Vereinsname') }}<input name="name" required maxlength="200" value="{{ old('name') }}"></label>
<label>{{ __('Kennung') }}<input name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100" value="{{ old('slug') }}"></label>
<label>{{ __('Bestehendes Administratorkonto') }}<input name="username" required maxlength="255" autocomplete="off" value="{{ old('username') }}"></label>
<label>{{ __('Begründung') }}<input name="reason" required minlength="10" maxlength="500" value="{{ old('reason') }}"></label>
<div class="platform-actions"><button class="primary">@svg('heroicon-o-plus') {{ __('Verein erstellen') }}</button></div></form></section>
<section class="platform-section"><details><summary>{{ __('Neues persönliches Konto') }}</summary><form method="post" action="{{ route('clubunio.platform.account.create') }}" class="platform-form">@csrf
<label>{{ __('Benutzername') }}<input name="username" required maxlength="255" autocomplete="off"></label><label>{{ __('Anzeigename') }}<input name="display_name" required maxlength="255"></label>
<label>{{ __('Passwort') }}<input name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>{{ __('Passwort bestätigen') }}<input name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-user-plus') {{ __('Konto erstellen') }}</button></div></form></details></section>
@endsection
