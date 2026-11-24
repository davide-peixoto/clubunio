<x-filament-panels::page>
    @if(app(\App\ClubUnio\Services\ReactUi::class)->enabled())
    @php($inboxMetrics = [])
    @if($expenses !== null)@php($inboxMetrics[] = ['label' => __('work-inbox.expenses'), 'value' => $expenseCount, 'tone' => $expenseCount ? 'attention' : null])@endif
    @if($bexio !== null)@php($inboxMetrics[] = ['label' => trans('module_ui.attention').' · Bexio', 'value' => $bexio, 'tone' => $bexio ? 'attention' : null])@endif
    @include('clubunio.modules.stats', ['metrics' => $inboxMetrics])
    @endif
    @livewire(\App\ClubUnio\Widgets\PendingReservationRequests::class)

    @if ($expenses !== null)
        <x-filament::section :heading="__('work-inbox.expenses').' ('.$expenseCount.')'">
            <ul class="space-y-2">
                @forelse ($expenses as $expense)
                    <li><x-filament::link :href="$expense['url']">{{ $expense['label'] }}</x-filament::link></li>
                @empty
                    <li>{{ __('work-inbox.empty-expenses') }}</li>
                @endforelse
            </ul>
            @if ($expenseCount > $expenses->count())
                <x-filament::link :href="\App\ClubUnio\Resources\ExpenseResource::getUrl()">{{ __('work-inbox.open-expenses') }}</x-filament::link>
            @endif
        </x-filament::section>
    @endif

    @if ($bexio !== null)
        <x-filament::section :heading="__('work-inbox.bexio')">
            <p>{{ __('work-inbox.bexio-count :count', ['count' => $bexio]) }}</p>
            <x-filament::link :href="\App\ClubUnio\Pages\Synchronisation::getUrl(['tableFilters' => ['provider' => ['value' => 'bexio']]])">
                {{ __('work-inbox.open-sync') }}
            </x-filament::link>
        </x-filament::section>
    @endif
</x-filament-panels::page>
