<x-filament-panels::page>
    <div class="clubunio-display" x-data="{ full: false, error: '' }" x-ref="display" @fullscreenchange.document="full = document.fullscreenElement === $refs.display">
    <div class="clubunio-signage-controls">
        <x-filament::icon-button icon="heroicon-o-arrows-pointing-out" label="Vollbild umschalten" x-on:click="error = ''; if (!document.fullscreenEnabled) { error = 'Vollbild ist in diesem Browser nicht verfügbar.'; } else { (document.fullscreenElement ? document.exitFullscreen() : $refs.display.requestFullscreen()).catch(() => error = 'Vollbild ist in diesem Browser nicht verfügbar.'); }" />
        <span>{{ \Filament\Facades\Filament::getTenant()->name }}</span>
        <span role="alert" x-text="error"></span>
    </div>
    <div class="clubunio-signage" wire:poll.60s="refreshDisplay">
    @if (!empty($selectionError))<p role="alert">{{ $selectionError }}</p>@endif
    <div class="clubunio-signage-controls">
        <label><input type="checkbox" wire:model.live="followToday"> Heute</label>
        <label class="grid gap-1">Datum <input type="date" wire:model.live="date" class="rounded border p-2" required></label>
        <label class="grid gap-1">Trainingsperiode
            <select wire:model.live="periodId" class="rounded border p-2">
                <option value="">Periode wählen</option>
                @foreach ($periods as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
    </div>
    <section>
        <h2 class="text-lg font-semibold">Spielplan</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="p-3">Zeit</th><th class="p-3">Gastgeber</th><th class="p-3">Garderobe</th><th class="p-3">Gast</th><th class="p-3">Garderobe</th><th class="p-3">Spielfeld</th><th class="p-3">Bemerkungen</th></tr></thead>
                <tbody>
                @forelse ($competitions as $game)
                    <tr class="border-b"><td class="p-3 whitespace-nowrap">{{ substr($game->starts_at ?? '', 0, 5) ?: '–' }}</td>
                        <td class="p-3"><span class="clubunio-club-name"><x-clubunio.club-logo :url="$clubs->get($game->host_club_number)?->logo_url" /><span>{{ $game->host_name }} {{ $game->host_category }}</span></span></td>
                        <td class="p-3">{{ $game->hostRoom?->name }} {{ $game->hostSide?->name }}</td>
                        <td class="p-3"><span class="clubunio-club-name"><x-clubunio.club-logo :url="$clubs->get($game->guest_club_number)?->logo_url" /><span>{{ $game->guest_name }} {{ $game->guest_category }}</span></span></td>
                        <td class="p-3">{{ $game->guestRoom?->name }} {{ $game->guestSide?->name }}</td><td class="p-3">{{ $game->field?->name }}</td><td class="p-3 break-words">{{ $game->remarks }}</td></tr>
                @empty<tr><td colspan="7" class="p-3">Keine Spiele an diesem Datum.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section>
        <h2 class="text-lg font-semibold">Trainingsplan</h2>
        @if (!$periodId)<p class="py-3">Keine Trainingsperiode ausgewählt.</p>
        @else
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="p-3">Zeit</th><th class="p-3">Mannschaft</th><th class="p-3">Garderobe</th><th class="p-3">Ort</th></tr></thead>
                <tbody>@forelse ($trainings as $training)
                    <tr class="border-b"><td class="p-3 whitespace-nowrap">{{ substr($training->starts_at, 0, 5) }} – {{ substr($training->ends_at, 0, 5) }}</td><td class="p-3">{{ $training->teamSeason?->label }}</td><td class="p-3">{{ $training->room?->name }} {{ $training->side?->name }}</td><td class="p-3">{{ $training->hall ?: $training->field?->name }}</td></tr>
                @empty<tr><td colspan="4" class="p-3">Keine Trainings für diese Auswahl.</td></tr>@endforelse</tbody>
            </table></div>
        @endif
    </section>
    <section><h2 class="text-lg font-semibold">Anlagenstatus</h2>
        <ul class="divide-y">@forelse ($fields as $field)
            <li class="flex flex-wrap items-center gap-3 py-3"><span>{{ $field->field?->name }}</span><x-filament::badge :color="\App\ClubUnio\Services\StatusAppearance::palette($field->status?->active ? $field->status->name : 'Ohne Status')">{{ $field->status?->active ? $field->status->name : 'Unbekannt' }}</x-filament::badge><span>{{ $field->note }}</span></li>
        @empty<li class="py-3">Kein Anlagenstatus erfasst.</li>@endforelse</ul>
    </section>
    <section><h2 class="text-lg font-semibold">Meldungen</h2>
        @forelse ($notices as $notice)<article class="border-b py-3"><h3 class="font-semibold">{{ $notice->title }}</h3><p class="whitespace-pre-wrap break-words">{{ $notice->body }}</p></article>
        @empty<p class="py-3">Keine Meldungen an diesem Datum.</p>@endforelse
    </section>
    </div>
    <livewire:clubunio.signage-feeds :tenant-id="\Filament\Facades\Filament::getTenant()->id" />
    </div>
</x-filament-panels::page>
