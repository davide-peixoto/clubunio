@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1 class="text-2xl font-bold mb-6">Absenzen</h1>
@if($own->count() && $profileAllows($record ? 'update' : 'create'))
<details class="mb-6" @if($record || $errors->any()) open @endif>
    <summary>{{ $record ? 'Absenz bearbeiten' : 'Absenz erfassen' }}</summary>
    <form class="grid grid-cols-1 gap-4 mt-4 sm:grid-cols-2" method="post" action="{{ route('clubunio.planning.absence.save', [$tenant->slug, $record->id ?? null]) }}">
        @csrf @include('clubunio.workspace.context')
        <input type="hidden" name="revision" value="{{ old('revision', $record->revision ?? 0) }}">
        <label class="space-y-2"><span>Mitglied</span><x-filament::input.wrapper><x-filament::input.select name="member_id" required>
            @foreach($own as $member)@if(!$record || $member->id === $record->member_id)
                <option value="{{ $member->id }}" @selected(old('member_id', $record->member_id ?? '') == $member->id)>{{ $member->display_name }}</option>
            @endif @endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>Art</span><x-filament::input.wrapper><x-filament::input.select name="kind">
            @foreach($options['absence'] ?? [] as $option)@if($option->active)
                <option value="{{ $option->code }}" @selected(old('kind', $record->kind ?? 'ABWESEND') === $option->code)>{{ $option->name }}</option>
            @endif @endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>Von</span><x-filament::input.wrapper><x-filament::input name="starts_on" type="date" required :value="old('starts_on', $record->starts_on ?? '')" /></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>Bis</span><x-filament::input.wrapper><x-filament::input name="ends_on" type="date" required :value="old('ends_on', $record->ends_on ?? '')" /></x-filament::input.wrapper></label>
        <label class="space-y-2 sm:col-span-2"><span>Bemerkung</span><x-filament::input.wrapper><textarea class="fi-input block w-full" name="note" maxlength="1000" rows="3">{{ old('note', $record->note ?? '') }}</textarea></x-filament::input.wrapper></label>
        <div class="flex items-center gap-4 sm:col-span-2">
            <x-filament::button type="submit" icon="heroicon-o-check">Speichern</x-filament::button>
            @if($record)<x-filament::link :href="route('clubunio.planning.absences', $tenant->slug)">Abbrechen</x-filament::link>@endif
        </div>
    </form>
</details>
@endif
