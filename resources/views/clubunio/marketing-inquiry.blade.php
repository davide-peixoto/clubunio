<form method="post" class="inquiry-form" data-recipient="{{ $contact }}" data-subject="{{ $t('mail_subject') }}">
    <h3>{{ $t('cta_button') }}</h3>
    <div class="inquiry-grid">
        <label>{{ $t('inquiry_kind') }}
            <select name="kind" data-label="{{ $t('inquiry_kind') }}">
                @foreach(array_combine(['demo', 'quote', 'both'], explode('|', $t('inquiry_kinds'))) as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ $t('inquiry_club') }}<input name="club" data-label="{{ $t('inquiry_club') }}" autocomplete="organization" maxlength="120" required></label>
        <label>{{ $t('inquiry_name') }}<input name="name" data-label="{{ $t('inquiry_name') }}" autocomplete="name" maxlength="120" required></label>
        <label>{{ $t('inquiry_email') }}<input type="email" name="email" data-label="{{ $t('inquiry_email') }}" autocomplete="email" maxlength="254" required></label>
        <label>{{ $t('inquiry_count') }}<input type="number" name="members" data-label="{{ $t('inquiry_count') }}" min="1" max="1000000"></label>
    </div>
    <fieldset>
        <legend>{{ $t('inquiry_interests') }}</legend>
        @foreach(explode('|', $t('inquiry_options')) as $option)
            <label><input type="checkbox" name="interests" value="{{ $option }}"> {{ $option }}</label>
        @endforeach
    </fieldset>
    <label>{{ $t('inquiry_message') }}<textarea name="message" data-label="{{ $t('inquiry_message') }}" rows="4" maxlength="1200"></textarea></label>
    <p id="inquiry-note">{{ $t('inquiry_note') }} <a href="{{ route('clubunio.marketing.privacy', ['locale' => $locale]) }}">{{ $t('privacy') }}</a></p>
    <button type="submit" disabled class="button" aria-describedby="inquiry-note">{{ $t('inquiry_send') }}</button>
</form>
