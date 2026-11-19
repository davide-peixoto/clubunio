<x-filament-panels::page>
    <x-filament::section>
        <p>{{ $description }}</p>
        @if ($origin)
            <p class="mt-2">{{ $origin }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
