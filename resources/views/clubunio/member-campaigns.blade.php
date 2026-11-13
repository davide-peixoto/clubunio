<x-filament-panels::page>
    @php
        $channels = ['email' => __('campaign.email'), 'post' => __('campaign.post'), 'both' => __('campaign.both')];
        $open = $campaign && $campaign->released_at && ! $campaign->revoked_at && \Illuminate\Support\Carbon::parse($campaign->expires_at)->isFuture();
        $draft = $campaign && ! $campaign->released_at && ! $campaign->revoked_at;
    @endphp
    <form wire:submit="create" class="flex flex-wrap items-end gap-3">
        <label>{{ __('campaign.name') }} <x-filament::input.wrapper><x-filament::input wire:model="campaignTitle" maxlength="160" required /></x-filament::input.wrapper></label>
        <label>{{ __('campaign.channel') }} <x-filament::input.wrapper><x-filament::input.select wire:model="channel">
            @foreach($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label><input type="checkbox" wire:model="populate"> {{ __('campaign.populate') }}</label>
        <x-filament::button type="submit">{{ __('campaign.create') }}</x-filament::button>
        @error('campaignTitle')<p role="alert">{{ $message }}</p>@enderror
    </form>
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('campaign.title') }}">
        @foreach($campaigns as $entry)
            <x-filament::button wire:click="select({{ $entry->id }})" :color="$selected === $entry->id ? 'primary' : 'gray'">{{ $entry->title }}</x-filament::button>
        @endforeach
    </nav>
    @if($campaign)
        <div class="flex flex-wrap items-center gap-3">
            <h2>{{ $campaign->title }}</h2>
            <x-filament::badge>{{ $draft ? __('campaign.draft') : ($open ? __('campaign.open') : __('campaign.closed')) }}</x-filament::badge>
            <span>{{ __(':count Empfänger', ['count' => $count]) }}</span>
            @if($draft && $count && $pilot)<x-filament::button wire:click="release" wire:confirm="{{ __('campaign.release') }}?">{{ __('campaign.release') }}</x-filament::button>@endif
            @if($open)<x-filament::button color="danger" wire:click="revoke">{{ __('campaign.revoke') }}</x-filament::button>@endif
        </div>
        @if($draft)
            <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.350ms="search" :placeholder="__('campaign.search')" :aria-label="__('campaign.search')" /></x-filament::input.wrapper>
            @foreach($matches as $member)
                <div class="flex flex-wrap items-center gap-2" wire:key="match-{{ $member->id }}">
                    <span>{{ $member->first_name }} {{ $member->last_name }}</span>
                    <x-filament::button size="sm" wire:click="recipient({{ $member->id }}, '{{ $campaign->channel }}')">{{ __('campaign.add') }}</x-filament::button>
                </div>
            @endforeach
        @endif
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach($recipients as $row)
                <li class="flex flex-wrap items-center gap-3 py-2" wire:key="recipient-{{ $row->id }}">
                    <strong>{{ $row->first_name }} {{ $row->last_name }}</strong>
                    @if($draft)
                        <x-filament::input.wrapper><x-filament::input.select :aria-label="__('campaign.channel')" wire:change="recipient({{ $row->member_id }}, $event.target.value)">
                            @foreach($channels as $key => $label)<option value="{{ $key }}" @selected($row->channel === $key)>{{ $label }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                        <x-filament::button size="sm" color="gray" wire:click="recipient({{ $row->member_id }}, null)">{{ __('campaign.remove') }}</x-filament::button>
                    @else
                        <span>{{ $channels[$row->channel] }}</span>
                    @endif
                    @if(in_array($row->channel, ['email','both'], true))
                        <x-filament::badge>{{ !$row->email ? __('campaign.no_email') : __(match($row->mail_state) {
                            'sending' => 'campaign.sending', 'accepted' => 'campaign.accepted', 'failed' => 'campaign.failed',
                            'attention' => 'campaign.attention', default => 'campaign.pending',
                        }) }}</x-filament::badge>
                        @if($open && $tenant->slug === 'stage-mobile-pilot' && config('clubunio-integrations.test_mail_enabled') && str_ends_with(strtolower((string) $row->email), '@example.invalid') && !$row->request_id && in_array($row->mail_state, ['pending','failed'], true))
                            <x-filament::button size="sm" wire:click="testMail({{ $row->id }})">{{ __('campaign.test_mail') }}</x-filament::button>
                        @endif
                    @endif
                    @if(in_array($row->channel, ['post','both'], true))
                        @if(!$row->street || !$row->postal_code || !$row->city)
                            <x-filament::badge color="warning">{{ __('campaign.no_post') }}</x-filament::badge>
                        @elseif($open && !$row->request_id)
                            <x-filament::button size="sm" tag="a" :href="route('clubunio.campaign.letter', [$tenant->slug, $campaign->id, $row->id])">{{ __('campaign.letter') }}</x-filament::button>
                            @unless($row->posted_at)<x-filament::button size="sm" wire:click="markPosted({{ $row->id }})">{{ __('campaign.posted') }}</x-filament::button>@endunless
                        @endif
                        @if($row->posted_at)<x-filament::badge>{{ __('campaign.posted_state') }}</x-filament::badge>@endif
                    @endif
                    @if($row->request_state)<x-filament::badge>{{ __(match($row->request_state) {
                        'approved' => 'campaign.approved', 'rejected' => 'campaign.rejected',
                        'withdrawn' => 'campaign.withdrawn', default => 'campaign.pending',
                    }) }}</x-filament::badge>@endif
                </li>
            @endforeach
        </ul>
        @if($count > $limit && $limit < 5000)
            <x-filament::button wire:click="$set('limit', {{ min(5000, $limit + 50) }})">{{ __('campaign.more') }}</x-filament::button>
        @endif
    @endif
</x-filament-panels::page>
