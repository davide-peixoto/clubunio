<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/clubunio-signage.css') }}?v=66">
    <script src="{{ asset('js/clubunio-signage.js') }}?v=66" defer></script>
    <div class="sg-preview-controls">
        <label><input type="checkbox" wire:model.live="followToday"> Heute</label>
        <label>Datum <input type="date" wire:model.live="date" required></label>
        <label>Vorschauperiode <select wire:model.live="periodId"><option value="">Aktiver Trainingsplan: {{ $activePlan }}</option>@foreach($periods as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    </div>
    @if(!empty($selectionError))<p role="alert">{{ $selectionError }}</p>@endif
    <div class="sg-preview" wire:poll.60s="refreshDisplay">
        @include('clubunio.signage-board', ['publicDisplay'=>false])
    </div>
</x-filament-panels::page>
