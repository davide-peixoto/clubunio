<div class="cu-admin-native">
    @if($profileAllows('sync') || \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'competitions', 'sync'))
        <form class="mb-4" method="post" action="{{ route('clubunio.workspace.planning.sfv.refresh') }}">
            @csrf
            <input type="hidden" name="_workspace" value="{{ $workspaceToken }}">
            <input type="hidden" name="from" value="{{ $filters['from'] ?? '' }}"><input type="hidden" name="to" value="{{ $filters['to'] ?? '' }}">
            <x-filament::button type="submit" icon="heroicon-o-arrow-path">{{ __('Vom SFV aktualisieren') }}</x-filament::button>
        </form>
    @endif
    {{ $this->table }}
</div>
