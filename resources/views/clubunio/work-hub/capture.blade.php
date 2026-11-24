<x-filament-panels::page>
@if($errors->any())<div role="alert" class="cu-workflow-section">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif<div class="cu-workflow cu-work-hub">
<p class="cu-module-note">{{ trans($kind==='expense'?'daily.capture_hint':'work_hub.capture_hint') }}</p><label class="cu-workflow-field">{{ trans('work_hub.kind') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="kind">@foreach($kinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
@if($kind==='expense'&&!$receiptAvailable)<p role="status">{{ trans('daily.receipt_storage_missing') }}</p>@endif
<form wire:submit="save" class="cu-workflow-section">{{ $this->form }}</form>
</div></x-filament-panels::page>
