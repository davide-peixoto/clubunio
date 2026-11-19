<x-filament-panels::page>
    <style>
        .api-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:16px; }
        .api-form { display:grid; gap:16px; min-width:0; }
        .api-scopes { display:flex; flex-wrap:wrap; gap:12px 24px; }
        .api-scopes label { display:flex; align-items:center; gap:8px; }
        .api-scope-groups { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr)); gap:8px 24px; min-width:0; }
        .api-scope-groups fieldset { min-width:0; }
        .api-scope-group { min-width:0; align-self:start; border-bottom:1px solid #d1d5db; padding:8px 0; }
        .api-scope-group summary { cursor:pointer; padding:4px 0; }
        .api-scope-group h3 { display:inline; font-size:14px; font-weight:600; }
        .api-scope-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:12px 24px; padding:12px 0; }
        .api-scope-grid label { display:flex; align-items:flex-start; gap:8px; min-width:0; }
        .api-scope-grid input { flex-shrink:0; margin-top:2px; }
        .api-scope-grid span { min-width:0; overflow-wrap:anywhere; }
        .api-fields > label { min-width:0; }
        .api-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .api-table { min-width:760px; }
        .api-secret { overflow-wrap:anywhere; white-space:pre-wrap; user-select:all; }
        .api-section { display:grid; gap:16px; min-width:0; }
        .api-section h2 { font-size:18px; font-weight:600; }
        .api-errors { color:#b91c1c; }
        .api-request-id { max-width:14rem; overflow-wrap:anywhere; white-space:normal; }
    </style>

    @php
        $scopeGroups = collect($scopeLabels)->groupBy(fn ($label, $scope) => explode(':', $scope, 2)[0], preserveKeys: true);
        $moduleLabels = ['website' => __('Website')] + \App\ClubUnio\Services\PermissionCatalog::MODULES;
    @endphp

    <div class="api-scopes">
        <label><x-filament::input.checkbox wire:model.live="readOnly" />{{ __('Nur Lesen') }}</label>
        <x-filament::link href="/api-docs" icon="heroicon-o-book-open">{{ __('API-Dokumentation') }}</x-filament::link>
    </div>

    @if($issuedToken !== null)
        <section class="api-section" role="status" aria-label="{{ __('Neuer Token') }}">
            <h2>{{ __('Neuer Token') }}</h2>
            <pre class="api-secret">{{ $issuedToken }}</pre>
            <x-filament::button wire:click="clearToken" color="gray" icon="heroicon-o-x-mark">{{ __('Token ausblenden') }}</x-filament::button>
        </section>
    @endif

    @if($errors->any())
        <ul class="api-errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif

    <x-filament::section :heading="__('Integration erstellen')">
        <form wire:submit="createIntegration" class="api-form">
            <div class="api-fields">
                <label>{{ __('Name') }}<x-filament::input.wrapper><x-filament::input wire:model="name" required maxlength="120" /></x-filament::input.wrapper></label>
                <label>{{ __('Berechtigungsprofil') }}<x-filament::input.wrapper><x-filament::input.select wire:model="accessProfileId" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($profiles as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label>{{ __('Anfragen pro Minute') }}<x-filament::input.wrapper><x-filament::input type="number" wire:model="rateLimit" min="1" max="600" required /></x-filament::input.wrapper></label>
            </div>
            <div><x-filament::button wire:click="selectWebsiteScopes" color="gray" icon="heroicon-o-globe-alt" wire:loading.attr="disabled">{{ __('Website-Preset') }}</x-filament::button></div>
            <fieldset class="api-scope-groups"><legend>{{ __('Erlaubte Scopes') }}</legend>
                @foreach($scopeGroups as $module => $moduleScopes)
                    <details class="api-scope-group" wire:key="api-integration-scopes-{{ $module }}" @if(array_intersect($moduleScopes->keys()->all(), $integrationScopes)) open @endif>
                        <summary><h3>{{ $moduleLabels[$module] ?? $module }}</h3></summary>
                        <div class="api-scope-grid">@foreach($moduleScopes as $scope => $label)<label><x-filament::input.checkbox wire:model="integrationScopes" value="{{ $scope }}" /><span>{{ $label }}</span></label>@endforeach</div>
                    </details>
                @endforeach
            </fieldset>
            <div><x-filament::button type="submit" icon="heroicon-o-plus" wire:loading.attr="disabled">{{ __('Integration erstellen') }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section :heading="__('Token erstellen')">
        @php($selectedIntegration = $integrations->firstWhere('id', $integrationId))
        <form wire:submit="issueToken" class="api-form">
            <div class="api-fields">
                <label>{{ __('Integration') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="integrationId" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($integrations->where('active', true) as $integration)<option value="{{ $integration->id }}">{{ $integration->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label>{{ __('Tokenname') }}<x-filament::input.wrapper><x-filament::input wire:model="tokenName" required maxlength="120" autocomplete="off" /></x-filament::input.wrapper></label>
                <label>{{ __('Ablauf (') }}{{ config('app.timezone') }})<x-filament::input.wrapper><x-filament::input type="datetime-local" wire:model="expiresAt" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" max="{{ now()->addYear()->format('Y-m-d\TH:i') }}" required /></x-filament::input.wrapper></label>
            </div>
            <fieldset class="api-scope-groups"><legend>{{ __('Token-Scopes') }}</legend>
                @foreach($scopeGroups as $module => $moduleScopes)
                    @php($allowedScopes = $moduleScopes->only($selectedIntegration?->scopes ?? []))
                    @if($allowedScopes->isNotEmpty())
                        <details class="api-scope-group" wire:key="api-token-scopes-{{ $module }}" @if(array_intersect($allowedScopes->keys()->all(), $tokenScopes)) open @endif>
                            <summary><h3>{{ $moduleLabels[$module] ?? $module }}</h3></summary>
                            <div class="api-scope-grid">@foreach($allowedScopes as $scope => $label)<label><x-filament::input.checkbox wire:model="tokenScopes" value="{{ $scope }}" /><span>{{ $label }}</span></label>@endforeach</div>
                        </details>
                    @endif
                @endforeach
            </fieldset>
            <div><x-filament::button type="submit" icon="heroicon-o-key" wire:loading.attr="disabled" :disabled="! $selectedIntegration?->active">{{ __('Token erstellen') }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <section class="api-section">
        <h2>{{ __('Integrationen und Tokens') }}</h2>
        {{ $this->table }}
    </section>

    <section class="api-section">
        <h2>{{ __('API-Synchronisationsaufträge') }}</h2>
        <div class="cu-admin-journal"><table class="api-table"><thead><tr><th>{{ __('Auftrag') }}</th><th>{{ __('Integration') }}</th><th>{{ __('Aktion / Objekt') }}</th><th>{{ __('Status') }}</th><th>{{ __('Prüfen') }}</th></tr></thead><tbody>
            @forelse($providerWorkRows as $row)
                <tr wire:key="api-work-{{ $row->id }}"><td>#{{ $row->id }}</td><td>#{{ $row->integration_id }}</td><td>{{ $providerActionLabels[$row->operation] ?? $row->operation }}<br>#{{ $row->object_id }}</td><td>{{ ['pending'=>__('Bereit'), 'awaiting_approval'=>__('Admin-Freigabe ausstehend'), 'running'=>__('In Ausführung'), 'succeeded'=>__('Abgeschlossen'), 'attention'=>__('Prüfung nötig'), 'rejected'=>__('Abgelehnt')][$row->state] }}@if($row->error_code)<br>{{ $row->error_code }}@endif</td><td><x-filament::icon-button icon="heroicon-o-eye" label="{{ __('Auftrag prüfen') }}" tooltip="{{ __('Auftrag prüfen') }}" wire:click="inspectProviderWork({{ $row->id }})" /></td></tr>
            @empty<tr><td colspan="5">{{ __('Keine API-Synchronisationsaufträge.') }}</td></tr>@endforelse
        </tbody></table></div>
        @if($providerWorkDetail)
            <h3>{{ $providerActionLabels[$providerWorkDetail['operation']] ?? $providerWorkDetail['operation'] }} #{{ $providerWorkDetail['id'] }}</h3>
            <dl><dt>{{ __('Objekt') }}</dt><dd>#{{ $providerWorkDetail['object_id'] }}</dd><dt>{{ __('Eingaben') }}</dt><dd><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($providerWorkDetail['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></dd></dl>
            @if($providerWorkDetail['error_code'])<p>{{ $providerWorkDetail['error_code'] }}</p>@endif
            @if($providerWorkDetail['result'] !== null)<details><summary>{{ __('Ergebnis') }}</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($providerWorkDetail['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
            <details><summary>{{ __('Verlauf') }}</summary><ul>@foreach($providerWorkDetail['events'] as $event)<li>{{ \Carbon\CarbonImmutable::parse($event->created_at)->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}: {{ $event->event }} {{ __('· Benutzer #') }}{{ $event->actor_id }} @if($event->code)({{ $event->code }})@endif</li>@endforeach</ul></details>
            @if($providerWorkDetail['state'] === 'awaiting_approval' && $providerWorkRevision === $providerWorkDetail['revision'])
                <div class="api-toolbar"><x-filament::button icon="heroicon-o-check" wire:click="executeProviderWork" wire:loading.attr="disabled">{{ __('Freigeben und ausführen') }}</x-filament::button><x-filament::button color="danger" icon="heroicon-o-x-mark" wire:click="rejectProviderWork" wire:loading.attr="disabled">{{ __('Ablehnen') }}</x-filament::button></div>
            @endif
        @endif
    </section>

    <section class="api-section">
        <h2>{{ __('API-Audit') }}</h2>
        <div class="cu-admin-journal"><table class="api-table"><thead><tr><th>{{ __('Zeit (') }}{{ config('app.timezone') }})</th><th>{{ __('Integration') }}</th><th>{{ __('Token-ID') }}</th><th>{{ __('Ereignis') }}</th><th>{{ __('Status') }}</th><th>{{ __('Request-ID') }}</th><th>{{ __('Dauer (ms)') }}</th></tr></thead><tbody>
            @forelse($auditRows as $row)
                <tr>
                    <td>{{ \Carbon\CarbonImmutable::parse($row->created_at)->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $row->integration_name ?? '-' }}@if($row->integration_id !== null)<br>#{{ $row->integration_id }}@endif</td>
                    <td>{{ $row->token_id ?? '-' }}</td><td>{{ $row->event }}</td><td>{{ $row->status ?? '-' }}</td>
                    <td class="api-request-id">{{ $row->request_id }}</td><td>{{ $row->duration_ms ?? '-' }}</td>
                </tr>
            @empty<tr><td colspan="7">{{ __('Keine API-Audit-Einträge.') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-filament-panels::page>
