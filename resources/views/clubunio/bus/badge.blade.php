@php($lookupRow = $statuses[$code] ?? null)
@php($rawLabel = $lookupRow->name ?? $label ?? $code)
@php($label = $lookupRow ? app(\App\ClubUnio\Services\LookupTranslations::class)->label('bus_statuses', $lookupRow, tenant: $tenant->id ?? null) : ($label ?? $code))
<span class="planning-badge"><span style="background-color:{{ $colors[\App\ClubUnio\Services\StatusAppearance::key($rawLabel)]??\App\ClubUnio\Services\StatusAppearance::defaultHex($rawLabel) }}"></span>{{ $label }}</span>
