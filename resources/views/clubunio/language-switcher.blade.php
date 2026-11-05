<form method="POST" action="{{ route('clubunio.language') }}" class="cu-language-switcher">
    @csrf
    <label for="cu-locale" class="cu-language-switcher-label">{{ __('Sprache') }}</label>
    <select id="cu-locale" name="locale" aria-label="{{ __('Sprache') }}" onchange="this.form.requestSubmit()" class="cu-language-switcher-select">
        @foreach(app(\App\ClubUnio\Services\LanguagePreferences::class)->enabled() as $code => $label)
            <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
        @endforeach
    </select>
</form>
