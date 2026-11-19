@extends('clubunio.public.layout')
@section('title', $copy['subject'])
@section('content')
<address>
    {{ $address['name'] }}<br>
    {{ $address['street'] }}<br>
    {{ $address['postal_code'] }} {{ $address['city'] }}
</address>
<h1>{{ $copy['subject'] }}</h1>
<p>{!! nl2br(e($copy['body'])) !!}</p>
<img src="{{ $qr }}" width="180" height="180" alt="{{ __('campaign.qr') }}">
<p style="overflow-wrap:anywhere"><a href="{{ $url }}">{{ $url }}</a></p>
@endsection
