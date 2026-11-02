@php($absence = $getRecord())
@if($own->contains('id', $absence->member_id))
<div class="flex items-center gap-3">
    @if($profileAllows('update'))
        <x-filament::icon-button icon="heroicon-o-pencil-square" tag="a" :href="route('clubunio.workspace.planning.absences', ['edit' => $absence->id])" label="Bearbeiten" />
    @endif
    @if($profileAllows('deactivate'))
        <form method="post" action="{{ route('clubunio.workspace.planning.absence.delete', $absence->id) }}" onsubmit="return confirm('Diese Absenz löschen?')">
            @csrf @method('DELETE')
            <input type="hidden" name="_workspace" value="{{ $workspaceToken }}">
            <input type="hidden" name="revision" value="{{ $absence->revision }}">
            <x-filament::icon-button type="submit" icon="heroicon-o-trash" color="danger" label="Absenz löschen" />
        </form>
    @endif
</div>
@endif
