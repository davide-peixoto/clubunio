@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<header class="fi-header"><h1 class="fi-header-heading">{{ __('Absenzen') }}</h1></header>
@if($record && $absenceMembers->count() && $profileAllows('update'))
<details class="mb-6" @if($record || $errors->any()) open @endif>
    <summary>{{ $record ? __('Absenz bearbeiten') : __('Absenz erfassen') }}</summary>
    <form class="cu-admin-form" method="post" action="{{ route('clubunio.planning.absence.save', [$tenant->slug, $record->id ?? null]) }}">
        @csrf @include('clubunio.workspace.context')
        <input type="hidden" name="revision" value="{{ old('revision', $record->revision ?? 0) }}">
        <label class="space-y-2"><span>{{ __('Mitglied') }}</span><x-filament::input.wrapper><x-filament::input.select name="member_id" required>
            @foreach($absenceMembers as $member)@if(!$record || $member->id === $record->member_id)
                <option value="{{ $member->id }}" @selected(old('member_id', $record->member_id ?? '') == $member->id)>{{ $member->display_name }}</option>
            @endif @endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>{{ __('Art') }}</span><x-filament::input.wrapper><x-filament::input.select name="kind">
            @foreach($options['absence'] ?? [] as $option)@if($option->active)
                <option value="{{ $option->code }}" @selected(old('kind', $record->kind ?? 'ABWESEND') === $option->code)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $option, tenant: $tenant->id ?? null) }}</option>
            @endif @endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>{{ __('Von') }}</span><x-filament::input.wrapper><x-filament::input name="starts_on" type="date" required :value="old('starts_on', $record->starts_on ?? '')" /></x-filament::input.wrapper></label>
        <label class="space-y-2"><span>{{ __('Bis') }}</span><x-filament::input.wrapper><x-filament::input name="ends_on" type="date" required :value="old('ends_on', $record->ends_on ?? '')" /></x-filament::input.wrapper></label>
        <label class="space-y-2 sm:col-span-2"><span>{{ __('Bemerkung') }}</span><x-filament::input.wrapper><textarea class="fi-input block w-full" name="note" maxlength="1000" rows="3">{{ old('note', $record->note ?? '') }}</textarea></x-filament::input.wrapper></label>
        <div class="flex items-center gap-4 sm:col-span-2">
            <x-filament::button type="submit" icon="heroicon-o-check">{{ __('Speichern') }}</x-filament::button>
            @if($record)<x-filament::link :href="route('clubunio.planning.absences', $tenant->slug)">{{ __('Abbrechen') }}</x-filament::link>@endif
        </div>
    </form>
</details>
@endif
