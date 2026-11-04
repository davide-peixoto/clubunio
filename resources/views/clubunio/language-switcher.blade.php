<form method="POST" action="{{ route('clubunio.language') }}" class="flex items-center gap-2 px-3 py-2">
    @csrf
    <label for="cu-locale" class="sr-only">{{ __('Sprache') }}</label>
    <select id="cu-locale" name="locale" aria-label="{{ __('Sprache') }}" onchange="this.form.requestSubmit()" class="rounded border border-gray-300 bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-white">
        @foreach(app(\App\ClubUnio\Services\LanguagePreferences::class)->enabled() as $code => $label)
            <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
        @endforeach
    </select>
</form>
