<x-filament-widgets::widget>
    <x-filament::section :heading="__('Offene Reservierungsanfragen')">
        <div class="grid gap-6 md:grid-cols-2">
            @foreach($groups as $kind => $group)
                <section>
                    <h3 class="font-semibold">{{ $kind === 'bus' ? __('Busreservierungen') : __('Sportplatzbelegung') }} ({{ $group['count'] }})</h3>
                    <ul class="mt-2 divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($group['rows'] as $row)
                            @php
                                $date = \Carbon\CarbonImmutable::parse($kind === 'bus' ? $row->starts_at : $row->date, 'Europe/Zurich')->setTimezone('Europe/Zurich');
                                $url = $kind === 'bus' ? route('clubunio.workspace.bus.show', $row->id)
                                    : \App\ClubUnio\Pages\PitchPlanning::getUrl(['date' => $date->toDateString(), 'booking' => $row->id]);
                            @endphp
                            <li class="py-2">
                                <x-filament::link :href="$url" icon="heroicon-o-arrow-right" :aria-label="__('Anfrage zur Genehmigung öffnen')">
                                    {{ $date->format($kind === 'bus' ? 'd.m.Y H:i' : 'd.m.Y') }}
                                    @if($kind === 'pitch') · {{ substr($row->starts_at, 0, 5) }}@endif
                                    · {{ $kind === 'bus' ? ($row->location ?: __('Busreservierungen')) : $row->opponent }}
                                </x-filament::link>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">{{ __('Keine offenen Anfragen') }}</li>
                        @endforelse
                    </ul>
                    <div class="mt-2 flex gap-2">
                        @if($group['page'] > 1)
                            <x-filament::icon-button icon="heroicon-o-chevron-left" :label="__('Vorherige Seite')" wire:click="$set('{{ $kind }}Page', {{ $group['page'] - 1 }})" />
                        @endif
                        @if($group['has_more'])
                            <x-filament::icon-button icon="heroicon-o-chevron-right" :label="__('Nächste Seite')" wire:click="$set('{{ $kind }}Page', {{ $group['page'] + 1 }})" />
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
