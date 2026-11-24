@once
    <link rel="stylesheet" href="{{ asset('css/clubunio-language-switcher.css') }}?v=1" data-navigate-track>
@endonce
<form method="POST" action="{{ route('clubunio.language') }}" class="cu-language-switcher">
    @csrf
    <label for="cu-locale" class="cu-language-switcher-label">{{ __('Sprache') }}</label>
    <span class="cu-language-switcher-control">
        <x-filament::icon icon="heroicon-o-language" class="cu-language-switcher-icon" />
        <select id="cu-locale" name="locale" aria-label="{{ __('Sprache') }}" onchange="this.form.requestSubmit()" class="cu-language-switcher-select">
            @foreach(app(\App\ClubUnio\Services\LanguagePreferences::class)->enabled() as $code => $label)
                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
            @endforeach
        </select>
        <x-filament::icon icon="heroicon-m-chevron-down" class="cu-language-switcher-chevron" />
    </span>
</form>
