@extends('clubunio.public.layout')
@section('title', $copy['subject'])
@section('content')
<address>
    {{ $member->first_name }} {{ $member->last_name }}<br>
    {{ $member->street }} {{ $member->house_number }}<br>
    {{ $member->postal_code }} {{ $member->city }}
</address>
<h1>{{ $copy['subject'] }}</h1>
<p>{!! nl2br(e($copy['body'])) !!}</p>
<img src="{{ $qr }}" width="180" height="180" alt="{{ __('campaign.qr') }}">
<p style="overflow-wrap:anywhere"><a href="{{ $url }}">{{ $url }}</a></p>
@endsection
