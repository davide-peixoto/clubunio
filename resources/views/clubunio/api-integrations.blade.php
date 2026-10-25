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
        .api-scroll { overflow-x:auto; min-width:0; max-width:100%; }
        .api-table { width:100%; min-width:760px; border-collapse:collapse; text-align:left; font-size:14px; }
        .api-table th,.api-table td { padding:12px; border-bottom:1px solid #d1d5db; vertical-align:top; }
        .api-secret { overflow-wrap:anywhere; white-space:pre-wrap; user-select:all; }
        .api-section { display:grid; gap:16px; min-width:0; }
        .api-section h2 { font-size:18px; font-weight:600; }
        .api-errors { color:#b91c1c; }
        .api-request-id { max-width:14rem; overflow-wrap:anywhere; white-space:normal; }
    </style>

    @php
        $scopeGroups = collect($scopeLabels)->groupBy(fn ($label, $scope) => explode(':', $scope, 2)[0], preserveKeys: true);
        $moduleLabels = ['website' => 'Website'] + \App\ClubUnio\Services\PermissionCatalog::MODULES;
    @endphp

    <div class="api-scopes">
        <label><x-filament::input.checkbox wire:model.live="readOnly" />Nur Lesen</label>
        <x-filament::link href="/api-docs" icon="heroicon-o-book-open">API-Dokumentation</x-filament::link>
    </div>

    @if($issuedToken !== null)
        <section class="api-section" role="status" aria-label="Neuer Token">
            <h2>Neuer Token</h2>
            <pre class="api-secret">{{ $issuedToken }}</pre>
            <x-filament::button wire:click="clearToken" color="gray" icon="heroicon-o-x-mark">Token ausblenden</x-filament::button>
        </section>
    @endif

    @if($errors->any())
        <ul class="api-errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif

    <section class="api-section">
        <h2>Integration erstellen</h2>
        <form wire:submit="createIntegration" class="api-form">
            <div class="api-fields">
                <label>Name<x-filament::input.wrapper><x-filament::input wire:model="name" required maxlength="120" /></x-filament::input.wrapper></label>
                <label>Berechtigungsprofil<x-filament::input.wrapper><x-filament::input.select wire:model="accessProfileId" required><option value="">Bitte wählen</option>@foreach($profiles as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label>Anfragen pro Minute<x-filament::input.wrapper><x-filament::input type="number" wire:model="rateLimit" min="1" max="600" required /></x-filament::input.wrapper></label>
            </div>
            <div><x-filament::button wire:click="selectWebsiteScopes" color="gray" icon="heroicon-o-globe-alt" wire:loading.attr="disabled">Website-Preset</x-filament::button></div>
            <fieldset class="api-scope-groups"><legend>Erlaubte Scopes</legend>
                @foreach($scopeGroups as $module => $moduleScopes)
                    <details class="api-scope-group" wire:key="api-integration-scopes-{{ $module }}" @if(array_intersect($moduleScopes->keys()->all(), $integrationScopes)) open @endif>
                        <summary><h3>{{ $moduleLabels[$module] ?? $module }}</h3></summary>
                        <div class="api-scope-grid">@foreach($moduleScopes as $scope => $label)<label><x-filament::input.checkbox wire:model="integrationScopes" value="{{ $scope }}" /><span>{{ $label }}</span></label>@endforeach</div>
                    </details>
                @endforeach
            </fieldset>
            <div><x-filament::button type="submit" icon="heroicon-o-plus" wire:loading.attr="disabled">Integration erstellen</x-filament::button></div>
        </form>
    </section>

    <section class="api-section">
        <h2>Token erstellen</h2>
        @php($selectedIntegration = $integrations->firstWhere('id', $integrationId))
        <form wire:submit="issueToken" class="api-form">
            <div class="api-fields">
                <label>Integration<x-filament::input.wrapper><x-filament::input.select wire:model.live="integrationId" required><option value="">Bitte wählen</option>@foreach($integrations->where('active', true) as $integration)<option value="{{ $integration->id }}">{{ $integration->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label>Tokenname<x-filament::input.wrapper><x-filament::input wire:model="tokenName" required maxlength="120" autocomplete="off" /></x-filament::input.wrapper></label>
                <label>Ablauf ({{ config('app.timezone') }})<x-filament::input.wrapper><x-filament::input type="datetime-local" wire:model="expiresAt" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" max="{{ now()->addYear()->format('Y-m-d\TH:i') }}" required /></x-filament::input.wrapper></label>
            </div>
            <fieldset class="api-scope-groups"><legend>Token-Scopes</legend>
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
            <div><x-filament::button type="submit" icon="heroicon-o-key" wire:loading.attr="disabled" :disabled="! $selectedIntegration?->active">Token erstellen</x-filament::button></div>
        </form>
    </section>

    <section class="api-section">
        <h2>Integrationen und Tokens</h2>
        <div class="api-scroll"><table class="api-table"><thead><tr><th>Integration</th><th>Profil / Limit</th><th>Scopes</th><th>Tokens</th><th>Aktion</th></tr></thead><tbody>
            @forelse($integrations as $integration)
                <tr wire:key="api-integration-{{ $integration->id }}">
                    <td>{{ $integration->name }}<br>{{ $integration->active ? 'Aktiv' : 'Deaktiviert' }}</td>
                    <td>{{ $profiles[$integration->access_profile_id] ?? 'Profil inaktiv oder nicht verfügbar' }}<br>{{ $integration->rate_limit }} / min</td>
                    <td>@foreach($integration->scopes ?? [] as $scope)<div>{{ $allScopeLabels[$scope] ?? $scope }}</div>@endforeach</td>
                    <td>@forelse($integration->tokens as $token)
                        <div wire:key="api-token-{{ $token->id }}">
                            <strong>{{ $token->name }}</strong><br>
                            @if($token->revoked_at)Widerrufen
                            @elseif(! $integration->active)Gesperrt
                            @elseif($token->expires_at && \Carbon\CarbonImmutable::parse($token->expires_at)->isPast())Abgelaufen
                            @else Aktiv @endif
                            <br>Ablauf: {{ $token->expires_at ? \Carbon\CarbonImmutable::parse($token->expires_at)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}
                            <br>Letzte Nutzung: {{ $token->last_used_at ? \Carbon\CarbonImmutable::parse($token->last_used_at)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}
                            @foreach($token->abilities ?? [] as $scope)<div>{{ $allScopeLabels[$scope] ?? $scope }}</div>@endforeach
                            @unless($token->revoked_at)<x-filament::icon-button icon="heroicon-o-no-symbol" color="danger" label="Token widerrufen" tooltip="Token widerrufen" wire:click="revokeToken({{ $token->id }})" wire:loading.attr="disabled" />@endunless
                        </div>
                    @empty Keine Tokens. @endforelse</td>
                    <td>@if($integration->active)<x-filament::icon-button icon="heroicon-o-stop-circle" color="danger" label="Integration deaktivieren" tooltip="Integration deaktivieren" wire:click="disableIntegration({{ $integration->id }})" wire:loading.attr="disabled" />@endif</td>
                </tr>
            @empty<tr><td colspan="5">Keine Integrationen.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="api-section">
        <h2>API-Audit</h2>
        <div class="api-scroll"><table class="api-table"><thead><tr><th>Zeit ({{ config('app.timezone') }})</th><th>Integration</th><th>Token-ID</th><th>Ereignis</th><th>Status</th><th>Request-ID</th><th>Dauer (ms)</th></tr></thead><tbody>
            @forelse($auditRows as $row)
                <tr>
                    <td>{{ \Carbon\CarbonImmutable::parse($row->created_at)->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $row->integration_name ?? '-' }}@if($row->integration_id !== null)<br>#{{ $row->integration_id }}@endif</td>
                    <td>{{ $row->token_id ?? '-' }}</td><td>{{ $row->event }}</td><td>{{ $row->status ?? '-' }}</td>
                    <td class="api-request-id">{{ $row->request_id }}</td><td>{{ $row->duration_ms ?? '-' }}</td>
                </tr>
            @empty<tr><td colspan="7">Keine API-Audit-Einträge.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-filament-panels::page>
