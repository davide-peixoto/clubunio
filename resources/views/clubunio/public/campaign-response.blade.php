@extends('clubunio.public.layout')
@section('title', $copy['subject'])
@section('content')
<h1>{{ $copy['subject'] }}</h1>
@if($complete)
    <p role="status">{{ __($acknowledge ? 'campaign.ack_received' : 'campaign.received') }}</p>
@else
    <p>{!! nl2br(e($copy['body'])) !!}</p>
    @if($errors->any())
        <ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    @if(in_array($kind, ['event','sponsor'], true))
        <form method="post">
            @csrf
            <label>{{ trans('campaign_ops.response') }}
                <select name="answer" required>
                    @foreach(['yes','no'] as $answer)<option value="{{ $answer }}" @selected(old('answer') === $answer)>{{ trans('campaign_ops.answer_'.$answer) }}</option>@endforeach
                </select>
            </label>
            <label>{{ trans('campaign_ops.fields.note') }}<textarea name="note" maxlength="500">{{ old('note') }}</textarea></label>
            @if($kind === 'sponsor')
                <fieldset class="form-grid">
                    <legend>{{ trans('campaign_ops.sponsor_details') }}</legend>
                    <label>{{ trans('campaign_ops.amount') }}<input name="amount_chf" inputmode="decimal" maxlength="9" value="{{ old('amount_chf') }}"></label>
                    <label>{{ trans('campaign_ops.amount_type') }}<select name="amount_type">
                        <option value="fixed">{{ trans('campaign_ops.fixed') }}</option>
                        <option value="per_lap" @selected(old('amount_type') === 'per_lap')>{{ trans('campaign_ops.per_lap') }}</option>
                    </select></label>
                    <label>{{ trans('campaign_ops.cap') }}<input name="cap_chf" inputmode="decimal" maxlength="9" value="{{ old('cap_chf') }}"></label>
                    @foreach(['sponsor_name','first_name','last_name','street','house_number','postal_code','city','country','email'] as $key)
                        <label>{{ trans('campaign_ops.fields.'.$key) }}
                            <input name="{{ $key }}" type="{{ $key === 'email' ? 'email' : 'text' }}" maxlength="{{ match($key) {
                                'country' => 2, 'postal_code','house_number' => 20, 'first_name','last_name','city' => 100, 'email' => 254, default => 160
                            } }}" value="{{ old($key, $key === 'country' ? 'CH' : '') }}">
                        </label>
                    @endforeach
                </fieldset>
            @endif
            <p><button type="submit">{{ trans('campaign_ops.submit_response') }}</button></p>
        </form>
    @elseif($acknowledge)
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
