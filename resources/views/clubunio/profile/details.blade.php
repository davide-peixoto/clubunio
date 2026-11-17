<dl style="overflow-wrap: anywhere">
    <dt>{{ __('Anzeigename') }}</dt>
    <dd>{{ $actor->display_name }}</dd>
    <dt>{{ __('Benutzername') }}</dt>
    <dd>{{ $actor->username }}</dd>
</dl>
@if($accountConsole)
    <h2>{{ trans('account-security.account_console') }}</h2>
    <p>{{ trans('account-security.account_console_description') }}</p>
    @if($backoffice ?? false)
        <x-filament::button tag="a" :href="$accountConsole" target="_blank" rel="noopener noreferrer" icon="heroicon-o-arrow-top-right-on-square">
            {{ trans('account-security.open_account_console') }}
        </x-filament::button>
    @else
        <a class="primary" href="{{ $accountConsole }}" target="_blank" rel="noopener noreferrer">
            {{ trans('account-security.open_account_console') }}
        </a>
    @endif
@endif
