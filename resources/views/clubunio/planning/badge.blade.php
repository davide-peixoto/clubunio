@php($lookupRow = $options[$category][$code] ?? null)
@php($rawLabel = $lookupRow->name ?? $code)
@php($label = $lookupRow ? app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $lookupRow, tenant: $tenant->id ?? null) : $code)
@php($color=$colors[\App\ClubUnio\Services\StatusAppearance::key($rawLabel)]??\App\ClubUnio\Services\StatusAppearance::defaultHex($rawLabel))
<span class="planning-badge"><span style="background:{{ preg_match('/^#[a-fA-F0-9]{6}$/',$color) ? $color : '#64748b' }}"></span>{{ $label }}</span>
