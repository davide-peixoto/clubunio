<div class="cu-module-header">
<x-filament-panels::header :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()"
    :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@if($record instanceof \App\ClubUnio\Models\Member)
<div class="cu-dossier-strip"><span class="cu-dossier-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($record->first_name ?? $record->company ?? ''),0,1).mb_substr(trim($record->last_name ?? ''),0,1)) }}</span>
<span>{{ trans('module_ui.member') }}</span><div class="cu-dossier-contact">
@if(filter_var($record->email, FILTER_VALIDATE_EMAIL))<a href="mailto:{{ $record->email }}">{{ trans('module_ui.email') }}</a>@endif
@if($phone = preg_replace('/[^+0-9]/', '', $record->mobile ?? ''))<a href="tel:{{ $phone }}">{{ trans('module_ui.phone') }}</a>@endif
</div></div>
@endif
@include('clubunio.modules.stats', compact('metrics','scope'))
</div>
