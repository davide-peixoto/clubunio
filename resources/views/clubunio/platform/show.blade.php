@extends('clubunio.platform.layout')
@section('content')
<div class="platform-heading"><div><span class="platform-eyebrow">{{ $tenant->slug }}</span><h1>{{ $tenant->name }}</h1></div><a href="{{ route('clubunio.platform.index') }}" class="icon-button" title="{{ __('Zur Vereinsübersicht') }}" aria-label="{{ __('Zur Vereinsübersicht') }}">@svg('heroicon-o-arrow-left')</a></div>
<nav class="platform-tabs" aria-label="{{ __('Vereinsbereiche') }}"><a href="#club">{{ __('Verein') }}</a><a href="#brand">{{ __('Vereinsdesign') }}</a>@if(config('clubunio-storage.profiles_enabled'))<a href="#document-storage">{{ __('Document storage') }}</a>@endif<a href="#domains">{{ __('Domains (') }}{{ $domains->count() }})</a><a href="#accounts">{{ __('Benutzer') }}</a><a href="#history">{{ __('Protokoll') }}</a></nav>
<section id="club" class="platform-section"><h2>{{ __('Verein') }}</h2><form method="post" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">
@include('clubunio.platform.fields',['operation'=>'name'])<label>{{ __('Name') }}<input name="name" value="{{ $tenant->name }}" maxlength="200" required></label><label>{{ __('Kennung') }}<input value="{{ $tenant->slug }}" readonly></label>
<label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-check') {{ __('Speichern') }}</button></div></form></section>
<section id="brand" class="platform-section"><h2>{{ __('Vereinsdesign') }}</h2><div class="platform-brand" style="--club-color:{{ $brand['color'] }};--club-ink:{{ $brand['ink'] }}"><img src="{{ $brand['logo'] }}" alt="{{ __('Vereinslogo') }}" width="64" height="64"><strong>{{ $brand['title'] }}</strong></div>
<form method="post" enctype="multipart/form-data" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">@include('clubunio.platform.fields',['operation'=>'brand'])
<label>{{ __('Vereinsfarbe') }}<input name="color" type="color" value="{{ $brand['color'] }}" required></label><label>{{ __('Logo-Adresse') }}<input name="logo" value="{{ $brand['logo'] }}" maxlength="2048"></label><label>{{ __('Upload logo') }}<input name="logo_file" type="file" accept="image/png,image/jpeg,image/webp"></label><label>{{ __('Anzeigetitel') }}<input name="title" value="{{ $brand['title'] }}" maxlength="100" required></label>
<label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-check') {{ __('Design speichern') }}</button></div></form></section>
@if(config('clubunio-storage.profiles_enabled'))
<section id="document-storage" class="platform-section">
    @php($providers = ['azure' => 'Azure Blob', 's3' => 'Amazon S3', 'google' => 'Google Workspace Drive', 'graph' => 'OneDrive / SharePoint', 'managed' => __('Private ClubUnio storage')])
    <h2>{{ __('Document storage') }}</h2>
    <p>{{ __('New uploads') }}: {{ $providers[$storageProfile?->provider] ?? ($tenant->slug === config('clubunio-integrations.azure.tenant_slug') ? __('Existing Azure storage') : __('No storage selected')) }}</p>
    @if($storageDestinations)
        <div class="table-scroll"><table>
            <thead><tr><th scope="col">{{ __('Storage provider') }}</th><th scope="col">{{ __('Storage destination') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>@foreach($storageDestinations as $destination)
                <tr><td>{{ $providers[$destination->provider] }}</td><td>{{ implode(' / ', array_filter([$destination->account, $destination->container])) }}</td>
                    <td>{{ $destination->active ? __('Selected for new uploads') : __('Existing files only') }}</td></tr>
            @endforeach</tbody>
        </table></div>
    @endif
    <details><summary>Azure Blob</summary>
        <form method="post" action="{{ route('clubunio.platform.change', $tenant->id) }}" class="platform-form">
            @include('clubunio.platform.fields', ['operation' => 'storage'])
            <input type="hidden" name="provider" value="azure">
            <label>{{ __('Azure storage account') }}<input name="storage_account" value="{{ $storageProfile?->provider === 'azure' ? $storageProfile->account : '' }}" maxlength="24" required></label>
            <label>{{ __('Private container') }}<input name="storage_container" value="{{ $storageProfile?->provider === 'azure' ? $storageProfile->container : '' }}" maxlength="63" required></label>
            <label>{{ __('Azure storage account key') }}<input name="storage_key" type="password" maxlength="256" autocomplete="new-password"></label>
            <label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label>
            @include('clubunio.platform.storage-actions')
        </form>
    </details>
    @foreach(['s3', 'google', 'graph'] as $provider)
        @if(\App\ClubUnio\Services\TenantDocumentStorage::enabled($provider))
            <details><summary>{{ $providers[$provider] }}</summary>
                <form method="post" action="{{ route('clubunio.platform.change', $tenant->id) }}" class="platform-form">
                    @include('clubunio.platform.fields', ['operation' => 'storage'])
                    <input type="hidden" name="provider" value="{{ $provider }}">
                    @php($current = $storageProfile?->provider === $provider ? $storageProfile : null)
                    @if($provider === 's3')
                        @php([$region, $owner] = explode(':', $current?->account ?? ':', 2))
                        <label>{{ __('AWS region') }}<input name="storage_account" value="{{ $region }}" maxlength="24" required></label>
                        <label>{{ __('AWS account ID') }}<input name="storage_owner" value="{{ $owner }}" inputmode="numeric" pattern="[0-9]{12}" maxlength="12" required></label>
                        <label>{{ __('Private S3 bucket') }}<input name="storage_container" value="{{ $current?->container }}" maxlength="63" required></label>
                    @elseif($provider === 'google')
                        <label>{{ __('Google shared drive ID') }}<input name="storage_container" value="{{ $current?->container }}" maxlength="255" required></label>
                    @else
                        <label>{{ __('Microsoft Entra tenant ID') }}<input name="storage_account" value="{{ $current?->account }}" maxlength="36" required></label>
                        <label>{{ __('Microsoft 365 drive ID') }}<input name="storage_container" value="{{ $current?->container }}" maxlength="255" required></label>
                        <label class="platform-check"><input name="storage_private_confirmed" type="checkbox" value="1" required>{{ __('External sharing is disabled and access is restricted to authorised users.') }}</label>
                    @endif
                    <label>{{ __(match ($provider) {
                        's3' => 'AWS credentials JSON',
                        'google' => 'Google service account JSON',
                        'graph' => 'Microsoft application credentials JSON',
                    }) }}<input name="storage_key" type="password" maxlength="16384" autocomplete="new-password" spellcheck="false"></label>
                    <label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label>
                    @include('clubunio.platform.storage-actions')
                </form>
            </details>
        @endif
    @endforeach
    @if(config('clubunio-storage.managed_enabled'))
    <details><summary>{{ __('Private ClubUnio storage') }}</summary>
        <form method="post" action="{{ route('clubunio.platform.change', $tenant->id) }}" class="platform-form">
            @include('clubunio.platform.fields', ['operation' => 'storage'])
            <input type="hidden" name="provider" value="managed">
            <label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label>
            <div class="platform-actions"><button class="primary">@svg('heroicon-o-check') {{ __('Select for new uploads') }}</button></div>
        </form>
    </details>
    @endif
</section>
@endif
<section id="domains" class="platform-section"><h2>{{ __('Domains') }}</h2>
@forelse($domains as $domain)<details><summary>{{ $domain->host }} <span class="platform-status {{ $domain->active ? 'is-active' : 'is-pending' }}">{{ $domain->active ? __('Aktiv') : __('Inaktiv') }}</span></summary><form method="post" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">@include('clubunio.platform.fields',['operation'=>'domain'])@include('clubunio.platform.domain-fields')</form></details>@empty<p>{{ __('Keine Domains zugeordnet.') }}</p>@endforelse
<details><summary>{{ __('Neue Domain') }}</summary><form method="post" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">@include('clubunio.platform.fields',['operation'=>'domain'])@include('clubunio.platform.domain-fields',['domain'=>null])</form></details></section>
<section id="accounts" class="platform-section"><h2>{{ __('Benutzerzuordnungen') }}</h2><div class="table-scroll"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Benutzername') }}</th><th>{{ __('Rolle') }}</th><th>{{ __('Portal') }}</th><th>{{ __('Konto') }}</th></tr></thead><tbody>@forelse($accounts->take(200) as $account)<tr><td>{{ $account->display_name }}</td><td>{{ $account->username }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('account_roles', ($roles->firstWhere('code', $account->role) ?? ['name' => $account->role]), tenant: $tenant->id ?? null) }}</td><td>{{ $account->portal_enabled ? __('Aktiv') : __('Inaktiv') }}</td><td>{{ $account->active ? __('Aktiv') : __('Inaktiv') }}</td></tr>@empty<tr><td colspan="5">{{ __('Keine persönlichen Konten zugeordnet.') }}</td></tr>@endforelse</tbody></table></div>
@if($accounts->count()>200)<p>{{ __('Weitere Zuordnungen vorhanden.') }}</p>@endif
<form method="post" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">@include('clubunio.platform.fields',['operation'=>'membership'])
<label>{{ __('Bestehender Benutzername') }}<input name="username" maxlength="255" autocomplete="off" list="platform-accounts" required><datalist id="platform-accounts">@foreach($accounts as $account)<option value="{{ $account->username }}">{{ $account->display_name }}</option>@endforeach</datalist></label><label>{{ __('Rolle') }}<select name="role" required><option value="" selected disabled>{{ __('Rolle auswählen') }}</option>@foreach($roles as $role)<option value="{{ $role->code }}">{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('account_roles', $role, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Berechtigungsprofil') }}<select name="access_profile_id"><option value="">{{ __('Kein Profil') }}</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach</select></label>
<label class="platform-check"><input type="hidden" name="portal_enabled" value="0"><input type="checkbox" name="portal_enabled" value="1">{{ __('Portalzugang') }}</label><label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label><div class="platform-actions"><button class="primary">@svg('heroicon-o-user-plus') {{ __('Zuordnung speichern') }}</button></div></form>
<details><summary>{{ __('Vereinszugang sperren') }}</summary><form method="post" action="{{ route('clubunio.platform.change',$tenant->id) }}" class="platform-form">@include('clubunio.platform.fields',['operation'=>'revoke'])<label>{{ __('Benutzername') }}<input name="username" maxlength="255" list="platform-accounts" required></label><label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label><div class="platform-actions"><button>@svg('heroicon-o-lock-closed') {{ __('Vereinszugang sperren') }}</button></div></form></details></section>
<section id="history" class="platform-section"><h2>{{ __('Letzte Änderungen') }}</h2><div class="table-scroll"><table><thead><tr><th>{{ __('Zeitpunkt') }}</th><th>{{ __('Verantwortlich') }}</th><th>{{ __('Änderung') }}</th><th>{{ __('Begründung') }}</th></tr></thead><tbody>@forelse($changes as $change)<tr><td>{{ \Carbon\Carbon::parse($change->created_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $change->display_name }}</td><td>{{ ['tenant.create'=>__('Verein erstellt'),'tenant.name'=>__('Vereinsname'),'tenant.brand'=>__('Vereinsdesign'),'tenant.storage'=>__('Document storage'),'tenant.domain'=>__('Domain'),'tenant.membership'=>__('Benutzerzuordnung')][$change->action] ?? $change->action }}</td><td>{{ json_decode($change->details,true)['reason'] ?? '' }}</td></tr>@empty<tr><td colspan="4">{{ __('Keine Plattformänderungen vorhanden.') }}</td></tr>@endforelse</tbody></table></div></section>
@endsection
