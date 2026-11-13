@extends('clubunio.public.layout')
@section('title', __('campaign.update'))
@section('content')
<address>
    {{ $member->first_name }} {{ $member->last_name }}<br>
    {{ $member->street }} {{ $member->house_number }}<br>
    {{ $member->postal_code }} {{ $member->city }}
</address>
<h1>{{ __('campaign.update') }}</h1>
<p>{{ __('Bitte aktualisiere deine Kontaktdaten für :club. Die Geschäftsstelle prüft deine Änderungen.', ['club' => $tenant->name]) }}</p>
<img src="{{ $qr }}" width="180" height="180" alt="{{ __('campaign.qr') }}">
<p style="overflow-wrap:anywhere"><a href="{{ $url }}">{{ $url }}</a></p>
@endsection
