@php($label=$options[$category][$code]->name??$code)
@php($color=$colors[\App\ClubUnio\Services\StatusAppearance::key($label)]??\App\ClubUnio\Services\StatusAppearance::defaultHex($label))
<span class="planning-badge"><span style="background:{{ preg_match('/^#[a-fA-F0-9]{6}$/',$color) ? $color : '#64748b' }}"></span>{{ $label }}</span>
