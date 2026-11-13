@extends('clubunio.public.layout')
@section('title', __('campaign.update'))
@section('content')
<h1>{{ __('campaign.update') }}</h1>
@if($complete)
    <p role="status">{{ __('campaign.received') }}</p>
@else
    <p>{{ __('campaign.instructions') }}</p>
    @if($errors->any())
        <ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="post">
        @csrf
        <input type="hidden" name="_version" value="{{ $version }}">
        <div class="form-grid">
            @foreach(\App\ClubUnio\Services\MemberCampaignService::FIELDS as $field)
                <label>{{ __(\App\ClubUnio\Services\MemberChangeService::FIELDS[$field]) }}
                    <input name="{{ $field }}" type="{{ $field === 'email' ? 'email' : ($field === 'mobile' ? 'tel' : 'text') }}"
                        maxlength="{{ match($field) {'house_number', 'postal_code' => 20, 'mobile' => 50, 'email' => 254, default => 255} }}"
                        value="{{ old($field) }}">
                </label>
            @endforeach
        </div>
        <p><button type="submit">{{ __('campaign.submit') }}</button></p>
    </form>
@endif
@endsection
