@if($migrationRows->isNotEmpty())
<details>
    <summary>{{ __('keycloak.migration') }}</summary>
    <form method="post" action="{{ route('clubunio.platform.keycloak-migration', $tenant->id) }}">
        @csrf
        <div class="table-scroll"><table>
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('keycloak.recipient') }}</th><th>{{ __('keycloak.result') }}</th></tr></thead>
            <tbody>
            @foreach($migrationRows as $row)
                <tr><td>{{ $row['name'] }}</td><td>{{ $row['email'] }}</td><td>
                    @php($statusKey = 'keycloak.status.'.$row['status'])
                    {{ __($statusKey) }}
                    @if(in_array($row['status'], ['pending','invited'], true))
                        <input type="checkbox" name="account_ids[]" value="{{ $row['id'] }}" aria-label="{{ __('keycloak.select_account') }}: {{ $row['name'] }}">
                        <button name="account_id" value="{{ $row['id'] }}">{{ __($row['status'] === 'invited' ? 'keycloak.resend' : 'keycloak.migration') }}</button>
                    @endif
                </td></tr>
            @endforeach
            </tbody>
        </table></div>
        <button>{{ __('keycloak.migration_selected') }}</button>
    </form>
</details>
@endif
