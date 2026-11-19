@extends('clubunio.portal.layout')
@section('content')
@php($money = fn($cents) => \App\ClubUnio\Services\SponsorRunMoney::format($cents))
<div class="page-heading">
    <div><h1>{{ $event->title }}</h1><p>{{ $event->event_date->format('d.m.Y') }} · {{ $event->place }} · {{ trans('portal-shell.pledge_deadline') }} {{ $event->registration_deadline->format('d.m.Y') }}</p></div>
    <a class="cu-portal-secondary" href="{{ route('clubunio.portal.sponsor-runs', [$tenant->slug, 'scope' => $filters['scope']]) }}">@svg('heroicon-o-arrow-left') {{ __('Sponsorenlauf') }}</a>
</div>
@if(session('sponsor_run_message'))<p role="status">{{ session('sponsor_run_message') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<div class="cu-portal-list">
<form method="get" class="toolbar"><input type="hidden" name="scope" value="{{ $filters['scope'] }}"><label>{{ __('Suche') }}<input type="search" name="search" maxlength="100" value="{{ $filters['search']??'' }}"></label><label>{{ __('Mannschaft') }}<select name="team_season_id"><option value="">{{ __('Alle') }}</option>@foreach($teams as $id=>$name)<option value="{{ $id }}" @selected(($filters['team_season_id']??null)==$id)>{{ $name }}</option>@endforeach</select></label><button class="icon-button" type="submit" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button></form>
<div class="table-scroll"><table>
    <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Mannschaft') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Runden') }}</th><th scope="col">{{ __('Zusagen') }}</th></tr></thead>
    <tbody>
        @forelse($participants as $p)
            <tr @if((int) $p->id === (int) $selected?->id) class="cu-portal-current-row" @endif>
                <td><a href="{{ request()->fullUrlWithQuery(['participant' => $p->id, 'pledges' => null]) }}#cu-participation" @if((int) $p->id === (int) $selected?->id) aria-current="true" @endif>{{ $p->first_name }} {{ $p->last_name }}</a></td>
                <td>{{ $p->team_label }}</td>
                <td>
                    <span class="cu-portal-status" @if($p->checked_in_at) data-state="active" @endif>{{ $p->checked_in_at ? __('Eingecheckt') : ($p->required ? __('Pflichtteilnahme') : __('Teilnahme entfallen')) }}</span>
                    @if($p->checked_in_at)<small>{{ $p->required ? __('Pflichtteilnahme') : __('Teilnahme entfallen') }}</small>@endif
                </td>
                <td>{{ $p->draft_laps ?? '-' }} <small>{{ $p->final_laps ?? '-' }} {{ __('/ final') }}</small></td>
                <td>
                    @isset($summaries[$p->id])
                        @php($summary = $summaries[$p->id])
                        {{ $summary['pledge_count'] }} · {{ $money($summary['total_cents']) }}
                        <small>{{ $money($summary['fixed_cents']) }} {{ __('Fixbetrag') }} · {{ $money($summary['per_lap_cents']) }} {{ __('Pro Runde') }} {{ __('· Mit Obergrenze') }} {{ $summary['capped_count'] }}</small>
                    @endisset
                </td>
            </tr>
        @empty
            @include('clubunio.portal.empty-row', ['columns' => 5, 'icon' => 'heroicon-o-users', 'message' => trans('portal-shell.no_participants')])
        @endforelse
    </tbody>
</table></div>
{{ $participants->links('pagination::simple-default') }}
</div>
@if($selected)
<section id="cu-participation" class="cu-portal-section">
<h2>{{ __('Teilnahme #') }}{{ $selected->id }}</h2>
@if($pledges)
<section class="cu-portal-section">
<h3>{{ __('Zusagen') }}</h3>
<div class="cu-portal-list"><div class="table-scroll"><table>
    <thead><tr><th scope="col">{{ __('Sponsorname') }}</th><th scope="col">{{ __('Art') }}</th><th scope="col" class="cu-portal-numeric">{{ __('Betrag (CHF)') }}</th><th scope="col" class="cu-portal-numeric">{{ __('Obergrenze (CHF)') }}</th></tr></thead>
    <tbody>
        @forelse($pledges as $g)
            <tr><td>{{ $g->sponsor_name }}</td><td>{{ $g->amount_type === 'fixed' ? __('Fixbetrag') : __('Pro Runde') }}</td><td class="cu-portal-numeric">{{ $money($g->amount_cents) }}</td><td class="cu-portal-numeric">{{ $money($g->cap_cents) }}</td></tr>
        @empty
            @include('clubunio.portal.empty-row', ['columns' => 4, 'icon' => 'heroicon-o-flag', 'message' => trans('portal-shell.no_pledges')])
        @endforelse
    </tbody>
</table></div>{{ $pledges->links('pagination::simple-default') }}</div>
</section>
@endif
@if($selected->required && $selected->final_laps===null && ($rights['checkin']||$rights['laps']||$rights['approve']))
<section class="cu-portal-form-section"><h3>{{ __('Check-in') }} / {{ __('Runden') }}</h3>
<form class="registration-form" method="post" action="{{ route('clubunio.portal.sponsor-run.operations',[$tenant->slug,$selected->id]) }}">@csrf<input type="hidden" name="revision" value="{{ $selected->revision }}"><label>{{ __('Aktion') }}<select name="action">@if($rights['checkin'])<option value="checkin">{{ __('Check-in') }}</option>@endif @if($rights['laps'])<option value="laps">{{ __('Rundenentwurf') }}</option>@endif @if($rights['approve'])<option value="approve">{{ __('Runden endgültig freigeben') }}</option>@endif</select></label><label><input type="checkbox" name="checked_in" value="1" @checked($selected->checked_in_at)> {{ __('Eingecheckt') }}</label><label>{{ __('Runden') }}<input name="laps" type="number" min="0" max="10000" value="{{ $selected->draft_laps }}"></label>@if($rights['approve'])<label class="full-width"><input type="checkbox" name="confirm_final" value="1"> {{ __('Ich bestätige den aktuellen Rundenentwurf endgültig') }}</label>@endif<button class="primary" type="submit">{{ __('Erfassung speichern') }}</button></form>
</section>
@endif
@if($canAdd)
<section class="cu-portal-form-section"><h3>{{ __('Zusage erfassen') }}</h3><form class="registration-form" method="post" action="{{ route('clubunio.portal.sponsor-run.pledge',[$tenant->slug,$selected->id]) }}">@csrf<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}"><label>{{ __('Sponsorname') }}<input name="sponsor_name" required maxlength="160" value="{{ old('sponsor_name') }}"></label><label>{{ __('Art') }}<select name="amount_type"><option value="per_lap">{{ __('Pro Runde') }}</option><option value="fixed">{{ __('Fixbetrag') }}</option></select></label><label>{{ __('Betrag (CHF)') }}<input inputmode="decimal" name="amount_chf" maxlength="12" required value="{{ old('amount_chf') }}"></label><label>{{ __('Obergrenze (CHF)') }}<input inputmode="decimal" name="cap_chf" maxlength="12" value="{{ old('cap_chf') }}"></label>
<label>{{ __('Zahlungsweg') }}<select name="payment_mode"><option value="invoice">{{ __('Rechnung') }}</option><option value="collective">{{ __('Sammelzahlung') }}</option></select></label><label class="full-width"><input type="checkbox" name="collector_confirmed" value="1"> {{ __('Ich übernehme diese Sammelzahlung persönlich') }}</label><label>{{ __('Empfänger') }}<select name="recipient_type"><option value="person">{{ __('Person') }}</option><option value="company">{{ __('Firma') }}</option></select></label>
@foreach(['company'=>__('Firma'),'first_name'=>__('Vorname / Kontakt'),'last_name'=>__('Nachname'),'street'=>__('Strasse'),'house_number'=>__('Hausnummer'),'postal_code'=>__('PLZ'),'city'=>__('Ort'),'country'=>__('Land (ISO)'),'email'=>__('E-Mail')] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$key==='country'?'CH':'') }}" @required(!in_array($key,['company','house_number'])) @if($key==='email') type="email" maxlength="254" @elseif($key==='country') minlength="2" maxlength="2" pattern="[A-Z]{2}" @else maxlength="160" @endif></label>@endforeach
<button class="primary" type="submit">{{ __('Zusage speichern') }}</button></form></section>
@endif
@if($canShare)
<section class="cu-portal-form-section"><h3>{{ __('Sponsorenlink') }}</h3><form class="registration-form" method="post" action="{{ route('clubunio.portal.sponsor-run.share',[$tenant->slug,$selected->id]) }}">@csrf<label>{{ __('Aktion') }}<select name="action"><option value="show">{{ __('Anzeigen') }}</option><option value="create">{{ __('Erstellen') }}</option><option value="rotate">{{ __('Ersetzen') }}</option><option value="revoke">{{ __('Widerrufen') }}</option></select></label><label>{{ __('Öffentlicher Alias') }}<input name="alias" maxlength="80"></label><label><input type="checkbox" name="consent" value="1"> {{ __('Diesen Alias ausdruecklich öffentlich freigeben') }}</label><button class="primary" type="submit">{{ __('Sponsorenlink aktualisieren') }}</button></form>
@if(session('sponsor_run_share.url'))<p class="cu-portal-prose"><a href="{{ session('sponsor_run_share.url') }}" rel="noreferrer">{{ session('sponsor_run_share.url') }}</a></p>@endif
</section>
@endif
</section>
@endif
@endsection
