@if(session('marketing_inquiry_sent'))
    <p role="status">{{ $t('inquiry_success') }}</p>
@endif
@if((session('errors'))?->has('inquiry'))
    <p role="alert">{{ (session('errors'))?->first('inquiry') }}</p>
@endif
<form method="post" action="{{ route('clubunio.marketing.inquiry', ['locale' => $locale]) }}" class="inquiry-form">
    @csrf
    <input type="hidden" name="plan" value="{{ old('plan') }}">
    <h3>{{ $t('cta_button') }}</h3>
    <div class="inquiry-grid">
        <label>{{ $t('inquiry_kind') }}
            <select name="kind">
                @foreach(array_combine(['demo', 'quote', 'both'], explode('|', $t('inquiry_kinds'))) as $key => $label)
                    <option value="{{ $key }}" @selected(old('kind') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ $t('inquiry_club') }}<input name="club" value="{{ old('club') }}" autocomplete="organization" maxlength="120" required></label>
        <label>{{ $t('inquiry_name') }}<input name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120" required></label>
        <label>{{ $t('inquiry_email') }}<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="254" required></label>
        <label>{{ $t('inquiry_count') }}<input type="number" name="members" value="{{ old('members') }}" min="1" max="1000000"></label>
    </div>
    <fieldset>
        <legend>{{ $t('inquiry_interests') }}</legend>
        @foreach(explode('|', $t('inquiry_options')) as $option)
            <label><input type="checkbox" name="interests[]" value="{{ $option }}" @checked(in_array($option, (array) old('interests', []), true))> {{ $option }}</label>
        @endforeach
    </fieldset>
    <label>{{ $t('inquiry_message') }}<textarea name="message" rows="4" maxlength="1200">{{ old('message') }}</textarea></label>
    <p id="inquiry-note">{{ $t('inquiry_note') }} <a href="{{ route('clubunio.marketing.privacy', ['locale' => $locale]) }}">{{ $t('privacy') }}</a></p>
    <button type="submit" class="button" aria-describedby="inquiry-note">{{ $t('inquiry_send') }}</button>
</form>
