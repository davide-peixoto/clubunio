@extends('clubunio.portal.layout')
@section('content')
<h1>{{ __('Mitgliederanmeldungen') }}</h1>
<form method="get" class="filters">
    <label>{{ __('Suche') }}<input type="search" name="q" value="{{ $q }}" maxlength="120"></label>
    <label>{{ __('Status') }}<select name="status"><option value="">{{ __('Alle') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('registration_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
    <button type="submit" class="icon-button" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button>
</form>
<div class="table-scroll"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Status') }}</th><th>{{ __('Mannschaft / Saison') }}</th><th></th></tr></thead><tbody>
@forelse($registrations as $r)
<tr><td>{{ $r->first_name }} {{ $r->last_name }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('registration_statuses', ($r->status ?? []), tenant: $tenant->id ?? null) }}</td><td>{{ $r->teamSeason?->label }}</td><td><a href="{{ route('clubunio.portal.registration', ['tenant'=>$tenant->slug, 'registration'=>$r->id]) }}" title="{{ __('Anmeldung öffnen') }}" aria-label="{{ __('Anmeldung öffnen') }}">@svg('heroicon-o-chevron-right', 'action-icon')</a></td></tr>
@empty <tr><td colspan="4">{{ __('Keine Anmeldungen vorhanden.') }}</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $registrations->links('pagination::simple-default') }}</div>
@endsection
