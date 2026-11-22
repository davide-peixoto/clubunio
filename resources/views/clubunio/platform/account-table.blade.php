@php($hasMigration = $migrationRows->isNotEmpty())
@push('head')
    <link rel="stylesheet" href="{{ asset('css/clubunio-platform-accounts.css') }}">
@endpush
@if($hasMigration)
    <form id="platform-keycloak-selection" class="platform-account-toolbar" method="post" action="{{ route('clubunio.platform.keycloak-migration', $tenant->id) }}">
        @csrf
        <button class="primary">@svg('heroicon-o-arrow-up-right') {{ __('keycloak.migration_selected') }}</button>
    </form>
@endif
<div class="table-scroll">
    <table class="platform-account-table">
        <thead><tr>
            @if($hasMigration)<th class="platform-account-select" scope="col"><span aria-label="{{ __('keycloak.select_account') }}">@svg('heroicon-o-check-badge')</span></th>@endif
            <th scope="col">{{ __('Name') }}</th>
            <th scope="col">{{ __('Rolle') }}</th>
            <th scope="col">{{ __('Portal') }}</th>
            <th scope="col">{{ __('Konto') }}</th>
            @if($hasMigration)
                <th scope="col">{{ __('keycloak.result') }}</th>
                <th class="platform-account-action" scope="col">Keycloak</th>
            @endif
        </tr></thead>
        <tbody>
        @forelse($accounts->take(200) as $account)
            @php($row = $migrationRows->get($account->id))
            @php($selectable = in_array($row['status'] ?? null, ['pending', 'invited'], true))
            <tr>
                @if($hasMigration)
                    <td class="platform-account-select">
                        @if($selectable)
                            <input form="platform-keycloak-selection" type="checkbox" name="account_ids[]" value="{{ $account->id }}" aria-label="{{ __('keycloak.select_account') }}: {{ $account->display_name }}">
                        @endif
                    </td>
                @endif
                <td><strong>{{ $account->display_name }}</strong><span class="platform-account-username">{{ $account->username }}</span></td>
                <td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('account_roles', ($roles->firstWhere('code', $account->role) ?? ['name' => $account->role]), tenant: $tenant->id) }}</td>
                <td>{{ $account->portal_enabled ? __('Aktiv') : __('Inaktiv') }}</td>
                <td>{{ $account->active ? __('Aktiv') : __('Inaktiv') }}</td>
                @if($hasMigration)
                    <td>
                        @if($row)
                            @php($statusKey = 'keycloak.status.'.$row['status'])
                            <span @class(['platform-status', 'is-active' => $row['status'] === 'active', 'is-pending' => $row['status'] === 'invited'])>{{ __($statusKey) }}</span>
                        @endif
                    </td>
                    <td class="platform-account-action">
                        @if($selectable)
                            @php($actionLabel = __($row['status'] === 'invited' ? 'keycloak.resend' : 'keycloak.migration'))
                            <button type="submit" form="platform-keycloak-selection" name="account_id" value="{{ $account->id }}" class="icon-button" title="{{ $actionLabel }}" aria-label="{{ $actionLabel }}: {{ $account->display_name }}">
                                @if($row['status'] === 'invited')@svg('heroicon-o-arrow-path')@else @svg('heroicon-o-arrow-up-right')@endif
                            </button>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $hasMigration ? 7 : 4 }}">{{ __('Keine persönlichen Konten zugeordnet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
