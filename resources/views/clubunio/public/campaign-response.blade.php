@extends('clubunio.public.layout')
@section('title', $copy['subject'])
@section('content')
<h1>{{ $copy['subject'] }}</h1>
@if($complete)
    <p role="status">{{ __($acknowledge ? 'campaign.ack_received' : 'campaign.received') }}</p>
@else
    <p>{!! nl2br(e($copy['body'])) !!}</p>
    @if($acknowledge)
        <form method="post">@csrf <button type="submit">{{ __('campaign.ack_submit') }}</button></form>
    @else
    <p>{{ __('campaign.instructions') }}</p>
    @if($errors->any())
        <ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="post" enctype="multipart/form-data">
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
        @if(app(\App\ClubUnio\Services\ProfilePhotoService::class)->enabled())
            <p><label>{{ __('photo.title') }} <input type="file" name="portrait" accept="image/jpeg,image/png,image/webp"></label></p>
        @endif
        <p><button type="submit">{{ __('campaign.submit') }}</button></p>
    </form>
    @endif
@endif
@endsection
