@extends('clubunio.platform.layout')
@section('content')
<section class="platform-section">
    <h1>{{ __('keycloak.migration') }} · {{ $tenant->name }}</h1>
    <div class="table-scroll"><table>
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('keycloak.recipient') }}</th><th>{{ __('Vereine') }}</th><th>{{ __('keycloak.result') }}</th></tr></thead>
        <tbody>
        @foreach($rows as $row)
            @php($statusKey = 'keycloak.status.'.$row['status'])
            <tr><td>{{ $row['name'] }}</td><td>{{ $row['email'] }}</td><td>{{ implode(', ', array_column($row['tenants'], 'name')) }}</td><td>{{ __($statusKey) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @if(empty($sent) && collect($rows)->contains(fn ($row) => in_array($row['status'], ['pending','invited'], true)))
        <form method="post" action="{{ route('clubunio.platform.keycloak-migration', $tenant->id) }}">
            @csrf
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="preview_token" value="{{ $previewToken }}">
            <button>{{ __('keycloak.send_invitations') }}</button>
        </form>
    @endif
    <a href="{{ route('clubunio.platform.show', $tenant->id) }}#accounts">{{ __('Benutzer') }}</a>
</section>
@endsection
