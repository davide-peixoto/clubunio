@extends('clubunio.platform.layout')
@section('content')
<section class="platform-section">
    <h1>{{ __('platform_users.title') }}</h1>
    <details>
        <summary>{{ __('platform_users.create') }}</summary>
        <form method="post" action="{{ route('clubunio.platform.users.create') }}" class="platform-form">
            @csrf
            <label>{{ __('Benutzername') }}<input name="username" required maxlength="255" autocomplete="off"></label>
            <label>{{ __('Anzeigename') }}<input name="display_name" required maxlength="255"></label>
            <label>{{ __('Passwort') }}<input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
            <label>{{ __('Passwort bestätigen') }}<input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
            <label>{{ __('Begründung') }}<input name="reason" required minlength="10" maxlength="500"></label>
            <div class="platform-actions"><button class="primary">@svg('heroicon-o-user-plus') {{ __('platform_users.create') }}</button></div>
        </form>
    </details>
    <div class="table-scroll"><table>
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Konto') }}</th><th>Keycloak</th><th>{{ __('platform_users.edit') }}</th></tr></thead>
        <tbody>
        @foreach($rows as $row)
            @php($invitation = $invites->get($row->id))
            @php($mapped = in_array((int) $row->id, $mappedIds, true) || $invitation?->activated_at)
            <tr>
                <td><strong>{{ $row->display_name }}</strong><br>{{ $row->username }}</td>
                <td>{{ $row->active && $row->platform_active ? __('Aktiv') : __('Inaktiv') }}</td>
                <td>
                    {{ __($mapped ? 'platform_users.linked' : ($invitation?->invited_at ? 'keycloak.status.invited' : 'platform_users.unlinked')) }}
                    @if($invitesEnabled && !$mapped && $row->active && $row->platform_active && (int) $row->id !== (int) $actor->id && filter_var($row->username, FILTER_VALIDATE_EMAIL))
                        <details>
                            <summary>{{ __($invitation?->invited_at ? 'keycloak.resend' : 'keycloak.migration') }}</summary>
                            <form method="post" action="{{ route('clubunio.platform.users.invite', $row->id) }}">
                                @csrf
                                <input type="hidden" name="email" value="{{ mb_strtolower(trim($row->username)) }}">
                                <label><input type="checkbox" name="confirmed" value="1" required> {{ __('platform_users.confirm_recipient') }}: {{ $row->username }}</label>
                                <button>{{ __('keycloak.send_invitations') }}</button>
                            </form>
                        </details>
                    @endif
                </td>
                <td>
                    @if($row->platform_active)
                        <details><summary>{{ __('platform_users.edit') }}</summary>
                            <form method="post" action="{{ route('clubunio.platform.users.change', $row->id) }}" class="platform-form">
                                @csrf
                                <input type="hidden" name="operation" value="rename">
                                <label>{{ __('Anzeigename') }}<input name="display_name" required maxlength="255" value="{{ $row->display_name }}"></label>
                                <label>{{ __('Begründung') }}<input name="reason" required minlength="10" maxlength="500"></label>
                                <div class="platform-actions"><button>{{ __('Speichern') }}</button></div>
                            </form>
                            @if((int) $row->id !== (int) $actor->id)
                                <form method="post" action="{{ route('clubunio.platform.users.change', $row->id) }}" class="platform-form">
                                    @csrf
                                    <input type="hidden" name="operation" value="revoke">
                                    <label>{{ __('Begründung') }}<input name="reason" required minlength="10" maxlength="500"></label>
                                    <div class="platform-actions"><button>@svg('heroicon-o-lock-closed') {{ __('platform_users.revoke') }}</button></div>
                                </form>
                            @endif
                        </details>
                    @endif
                    @if((int) $row->id !== (int) $actor->id)
                        <details><summary>{{ __('platform_users.remove') }}</summary>
                            <form method="post" action="{{ route('clubunio.platform.users.change', $row->id) }}" class="platform-form">
                                @csrf
                                <input type="hidden" name="operation" value="remove">
                                <label>{{ __('Begründung') }}<input name="reason" required minlength="10" maxlength="500"></label>
                                <div class="platform-actions"><button>@svg('heroicon-o-trash') {{ __('platform_users.remove') }}</button></div>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $rows->links() }}
</section>
@endsection
