<x-filament-panels::page>
    {{ $this->table }}

    @if($event)
        <x-filament::section :heading="$event->title">
            <div class="space-y-4">
                <p>{{ $event->place }} · {{ $event->event_date->format('d.m.Y') }} {{ __('· Zusagefrist') }} {{ $event->registration_deadline->format('d.m.Y') }}</p>
                <div class="flex flex-wrap gap-3">
                    {{ $this->editEventAction }} {{ $this->cancelEventAction }} {{ $this->helperAction }} {{ $this->testOutboxAction }}
                    @if($canBill)
                        <x-filament::button tag="a" href="{{ url('/sponsor-run-billing').'?event='.$event->id }}" icon="heroicon-o-document-currency-dollar">{{ __('Abrechnung') }}</x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
        @livewire(\App\ClubUnio\Pages\Components\SponsorRunTable::class, ['kind' => 'participants', 'eventId' => $event->id], key('participants-'.$event->id))
        @livewire(\App\ClubUnio\Pages\Components\SponsorRunTable::class, ['kind' => 'helpers', 'eventId' => $event->id], key('helpers-'.$event->id))
        @if($canTestSend)
            @livewire(\App\ClubUnio\Pages\Components\SponsorRunTable::class, ['kind' => 'outbox', 'eventId' => $event->id], key('outbox-'.$event->id))
        @endif
    @endif

    @if($selected)
        <x-filament::section :heading="__('Teilnahme #:id', ['id' => $selected->id])">
            <div class="flex flex-wrap gap-3">{{ $this->operationAction }} {{ $this->pledgeAction }} {{ $this->shareAction }}</div>
            @if($shareUrl)<p class="break-all"><a href="{{ $shareUrl }}" rel="noreferrer">{{ $shareUrl }}</a></p>@endif
        </x-filament::section>
        @livewire(\App\ClubUnio\Pages\Components\SponsorRunTable::class, ['kind' => 'pledges', 'eventId' => $event->id, 'participantId' => $selected->id], key('pledges-'.$selected->id.'-'.$pledgeTableRevision))
    @endif
</x-filament-panels::page>
