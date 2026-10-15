<x-filament-panels::page>
    <div x-data="{ search: @js(is_string(request()->query('search')) ? mb_substr(request()->query('search'), 0, 100) : '') }" class="cu-settings">
        <label for="cu-settings-search" class="sr-only">Einstellungen suchen</label>
        <input id="cu-settings-search" type="search" x-model="search" placeholder="Einstellungen suchen" class="cu-settings-search">
        @foreach($sections as $label => $items)
            <section class="cu-settings-section" x-show="!search.trim() || @js(mb_strtolower($label.' '.implode(' ', array_map(fn($item) => $item->getLabel(), $items)))).includes(search.trim().toLocaleLowerCase())">
                <h2>{{ $label }}</h2>
                <ul>
                    @foreach($items as $item)
                        <li x-show="!search.trim() || @js(mb_strtolower($label.' '.$item->getLabel())).includes(search.trim().toLocaleLowerCase())">
                            <a href="{{ $item->getUrl() }}">
                                <x-filament::icon :icon="$item->getIcon() ?? 'heroicon-o-adjustments-horizontal'" />
                                <span>{{ $item->getLabel() }}</span>
                                <x-filament::icon icon="heroicon-o-chevron-right" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p x-cloak x-show="search.trim() && !@js(mb_strtolower(implode(' ', array_keys($sections)).' '.collect($sections)->flatten(1)->map(fn($item) => $item->getLabel())->implode(' '))).includes(search.trim().toLocaleLowerCase())" role="status">Keine Einstellungen gefunden.</p>
    </div>
</x-filament-panels::page>
