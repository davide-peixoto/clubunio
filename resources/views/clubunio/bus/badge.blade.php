@php($label=$label??$statuses[$code]->name??$code)
<span class="planning-badge"><span style="background-color:{{ $colors[\App\ClubUnio\Services\StatusAppearance::key($label)]??\App\ClubUnio\Services\StatusAppearance::defaultHex($label) }}"></span>{{ $label }}</span>
