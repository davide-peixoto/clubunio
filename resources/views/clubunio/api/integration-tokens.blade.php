<div style="padding:12px 16px;max-width:360px;overflow-wrap:anywhere">
@forelse($getRecord()['tokens'] as $token)
    <div wire:key="api-token-{{ $token->id }}" style="margin-bottom:12px">
        <strong>{{ $token->name }}</strong><br>
        @if($token->revoked_at){{ __('Widerrufen') }}
        @elseif(! $getRecord()['active']){{ __('Gesperrt') }}
        @elseif($token->expires_at && \Carbon\CarbonImmutable::parse($token->expires_at)->isPast()){{ __('Abgelaufen') }}
        @else {{ __('Aktiv') }} @endif
        <br>{{ __('Ablauf:') }} {{ $token->expires_at ? \Carbon\CarbonImmutable::parse($token->expires_at)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '–' }}
        <br>{{ __('Letzte Nutzung:') }} {{ $token->last_used_at ? \Carbon\CarbonImmutable::parse($token->last_used_at)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '–' }}
        <details><summary>{{ __('Scopes') }}</summary>@foreach($token->abilities ?? [] as $scope)<div>{{ $allScopeLabels[$scope] ?? $scope }}</div>@endforeach</details>
        @unless($token->revoked_at)<x-filament::icon-button icon="heroicon-o-no-symbol" color="danger" label="{{ __('Token widerrufen') }}" tooltip="{{ __('Token widerrufen') }}" wire:click="revokeToken({{ $token->id }})" wire:loading.attr="disabled" />@endunless
    </div>
@empty {{ __('Keine Tokens.') }} @endforelse
</div>
