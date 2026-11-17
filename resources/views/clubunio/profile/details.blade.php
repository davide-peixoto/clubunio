<div class="cu-profile">
    <section class="cu-profile-section" aria-labelledby="cu-profile-account">
        <div class="cu-profile-icon" aria-hidden="true">@svg('heroicon-o-user-circle')</div>
        <div class="cu-profile-content">
            <h2 id="cu-profile-account">{{ trans('account-security.account_details') }}</h2>
            <dl class="cu-profile-details">
                <div>
                    <dt>{{ __('Anzeigename') }}</dt>
                    <dd>{{ $actor->display_name }}</dd>
                </div>
                <div>
                    <dt>{{ __('Benutzername') }}</dt>
                    <dd>{{ $actor->username }}</dd>
                </div>
            </dl>
        </div>
    </section>
    @if($accountConsole)
        <section class="cu-profile-section" aria-labelledby="cu-profile-security">
            <div class="cu-profile-icon" aria-hidden="true">@svg('heroicon-o-shield-check')</div>
            <div class="cu-profile-content">
                <h2 id="cu-profile-security">{{ trans('account-security.account_console') }}</h2>
                <p>{{ trans('account-security.account_console_description') }}</p>
                <a class="cu-profile-action" href="{{ $accountConsole }}" target="_blank" rel="noopener noreferrer" aria-describedby="cu-profile-new-tab">
                    {{ trans('account-security.open_account_console') }}
                    @svg('heroicon-o-arrow-top-right-on-square', '', ['aria-hidden' => 'true'])
                </a>
                <span hidden id="cu-profile-new-tab">{{ trans('account-security.opens_new_tab') }}</span>
            </div>
        </section>
    @endif
</div>
