@php($absence = $getRecord())
@if($own->contains('id', $absence->member_id))
<div class="flex items-center gap-3">
    @if($profileAllows('deactivate'))
        <form method="post" action="{{ route('clubunio.workspace.planning.absence.delete', $absence->id) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('Diese Absenz löschen?') }}">
            @csrf @method('DELETE')
            <input type="hidden" name="_workspace" value="{{ $workspaceToken }}">
            <input type="hidden" name="revision" value="{{ $absence->revision }}">
            <x-filament::icon-button type="submit" icon="heroicon-o-trash" color="danger" :label="__('Absenz löschen')" />
        </form>
    @endif
</div>
@endif
