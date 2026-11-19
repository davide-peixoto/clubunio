<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-4">
            <label>{{ trans('material_returns.member') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="member">
                        <option value="">{{ trans('material_returns.all') }}</option>
                        @foreach ($members as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label><x-filament::input.checkbox wire:model.live="overdue" /> {{ trans('material_returns.overdue') }}</label>
            <label><x-filament::input.checkbox wire:model.live="completed" /> {{ trans('material_returns.completed') }}</label>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead><tr>
                    @foreach (['member', 'receipt', 'item', 'issued', 'open', 'due'] as $column)
                        <th scope="col" class="p-2 text-start">{{ trans('material_returns.'.$column) }}</th>
                    @endforeach
                    <th scope="col"><span class="sr-only">{{ trans('material_returns.details') }}</span></th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="return-{{ $row->id }}-{{ $row->position }}" class="border-t border-gray-200 dark:border-white/10">
                            <td class="p-2">{{ $row->member }}</td>
                            <td class="p-2">{{ $row->title }} · #{{ $row->id }}</td>
                            <th scope="row" class="p-2 text-start">{{ $row->position }}. {{ $row->description }}</th>
                            <td class="p-2">{{ $row->issued }}</td>
                            <td class="p-2">{{ $row->issued - $row->returned }}</td>
                            <td class="p-2">{{ $row->due_on ?? '—' }}</td>
                            <td class="p-2"><x-filament::button color="gray" wire:click="select({{ $row->id }}, {{ $row->position }})">{{ trans('material_returns.details') }}</x-filament::button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-2">{{ trans('material_returns.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </x-filament::section>
    @if ($history !== null)
        <x-filament::section :heading="trans('material_returns.history').' · #'.$receipt.' / '.$position">
            <ul class="space-y-2">
                @forelse ($history as $entry)
                    <li>{{ $entry->returned_on }} · {{ $entry->quantity }} · {{ $entry->condition }} · {{ $entry->recorded_name }}</li>
                @empty
                    <li>{{ trans('material_returns.empty_history') }}</li>
                @endforelse
            </ul>
            @if ($canWrite)
                <form wire:submit="book" class="mt-4 flex flex-wrap items-end gap-4">
                    @foreach (['quantity' => 'number', 'returned_on' => 'date', 'condition' => 'text'] as $field => $type)
                        <label>{{ trans('material_returns.'.($field === 'returned_on' ? 'date' : $field)) }}
                            <x-filament::input.wrapper><x-filament::input :type="$type" wire:model="booking.{{ $field }}" required /></x-filament::input.wrapper>
                            @error($field)<span role="alert">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ trans('material_returns.book') }}</x-filament::button>
                </form>
                <form wire:submit="deadline" class="mt-4 flex flex-wrap items-end gap-4">
                    <label>{{ trans('material_returns.due') }}
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="dueOn" /></x-filament::input.wrapper>
                        @error('due_on')<span role="alert">{{ $message }}</span>@enderror
                    </label>
                    <x-filament::button type="submit">{{ trans('material_returns.save_due') }}</x-filament::button>
                </form>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
