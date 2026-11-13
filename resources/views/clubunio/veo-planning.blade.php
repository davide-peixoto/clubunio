<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('veo.title') }}</x-slot>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('veo.manual') }}</p>
        <form wire:submit="showWeek" class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="veo-from" class="block text-sm font-medium">{{ __('veo.start_date') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="veo-from" type="date" wire:model="from" />
                </x-filament::input.wrapper>
                @error('from') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            </div>
            <x-filament::button type="submit">{{ __('veo.show_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="previousWeek">{{ __('veo.previous_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="nextWeek">{{ __('veo.next_week') }}</x-filament::button>
        </form>
        @if ($entries === [])
            <p>{{ __('veo.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr>
                            @foreach (['veo.match', 'veo.window', 'veo.field', 'veo.camera', 'veo.status'] as $column)
                                <th scope="col" class="px-3 py-2 text-start">{{ __($column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="veo-match-{{ $entry['match_id'] }}" class="border-t border-gray-200 dark:border-white/10">
                                <th scope="row" class="px-3 py-2 text-start">{{ $entry['title'] }}</th>
                                <td class="px-3 py-2">{{ $entry['starts_at']->format('d.m.Y H:i') }} – {{ $entry['ends_at']->format('d.m.Y H:i') }}</td>
                                <td class="px-3 py-2">{{ $fields->get($entry['field_id'])['name'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $entry['camera'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ __(sprintf('veo.%s', $entry['status'])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('veo.cameras') }}</x-slot>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('veo.camera_help') }}</p>
        @forelse ($fields as $field)
            <form wire:submit="saveCamera({{ $field['id'] }})" wire:key="veo-field-{{ $field['id'] }}" class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <label for="veo-field-{{ $field['id'] }}" class="block text-sm font-medium">{{ $field['name'] }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="veo-field-{{ $field['id'] }}" type="text" maxlength="120" wire:model="cameraNames.{{ $field['id'] }}" />
                    </x-filament::input.wrapper>
                    @error('cameraNames.'.$field['id']) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-filament::button type="submit">{{ __('veo.save') }}</x-filament::button>
            </form>
        @empty
            <p>{{ __('veo.no_fields') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('veo.teams') }}</x-slot>
        @forelse ($teams as $team)
            <div class="mb-3 flex items-center gap-3" wire:key="veo-team-{{ $team['id'] }}">
                <span>{{ $team['name'] }}</span>
                @if (in_array($team['id'], $enabledTeams, true))
                    <x-filament::button color="gray" wire:click="setTeam({{ $team['id'] }}, false)">{{ __('veo.disable') }}</x-filament::button>
                @else
                    <x-filament::button wire:click="setTeam({{ $team['id'] }}, true)">{{ __('veo.enable') }}</x-filament::button>
                @endif
            </div>
        @empty
            <p>{{ __('veo.no_teams') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
