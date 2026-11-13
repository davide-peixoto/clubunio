<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/clubunio-campaigns.css') }}?v=1">
    @php
        $channels = ['email' => __('campaign.email'), 'post' => __('campaign.post'), 'both' => __('campaign.both')];
        $open = $campaign && $campaign->released_at && ! $campaign->revoked_at && \Illuminate\Support\Carbon::parse($campaign->expires_at)->isFuture();
        $draft = $campaign && ! $campaign->released_at && ! $campaign->revoked_at;
    @endphp
    @if($canManage)
    <x-filament::section>
    <form wire:submit="create" class="cu-admin-toolbar cu-campaign-create">
        <label>{{ __('campaign.name') }} <x-filament::input.wrapper><x-filament::input wire:model="campaignTitle" maxlength="160" required /></x-filament::input.wrapper></label>
        <label>{{ __('campaign.channel') }} <x-filament::input.wrapper><x-filament::input.select wire:model="channel">
            @foreach($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label class="cu-campaign-check"><x-filament::input.checkbox wire:model="populate" /> {{ __('campaign.populate') }}</label>
        <x-filament::button type="submit" icon="heroicon-o-plus">{{ __('campaign.create') }}</x-filament::button>
        @error('campaignTitle')<p role="alert">{{ $message }}</p>@enderror
    </form>
    </x-filament::section>
    @endif
    <div class="cu-admin-journal cu-campaign-overview">
    <table><thead><tr><th scope="col">{{ __('campaign.name') }}</th><th scope="col">{{ __('campaign.channel') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead><tbody>
        @foreach($campaigns as $entry)
            <tr wire:key="campaign-{{ $entry->id }}">
                <td><x-filament::link tag="button" wire:click="select({{ $entry->id }})">{{ $entry->title }}</x-filament::link></td>
                <td>{{ $channels[$entry->channel] }}</td>
                <td><x-filament::badge>{{ !$entry->released_at && !$entry->revoked_at ? __('campaign.draft') : ($entry->revoked_at || !\Illuminate\Support\Carbon::parse($entry->expires_at)->isFuture() ? __('campaign.closed') : __('campaign.open')) }}</x-filament::badge></td>
            </tr>
        @endforeach
    </tbody></table>
    </div>
    @if($campaign)
        <div class="flex flex-wrap items-center gap-3">
            <h2>{{ $campaign->title }}</h2>
            <x-filament::badge>{{ $draft ? __('campaign.draft') : ($open ? __('campaign.open') : __('campaign.closed')) }}</x-filament::badge>
            <span>{{ __(':count Empfänger', ['count' => $count]) }}</span>
            @if($canManage && $draft && $total && $pilot)<x-filament::button wire:click="release" wire:confirm="{{ __('campaign.release') }}?">{{ __('campaign.release') }}</x-filament::button>@endif
            @if($canManage && $draft)<x-filament::button color="danger" icon="heroicon-o-trash" wire:click="deleteDraft" wire:confirm="{{ __('campaign.confirm_delete') }}">{{ __('campaign.delete') }}</x-filament::button>@endif
            @if($canManage && $open)<x-filament::button color="danger" wire:click="revoke">{{ __('campaign.revoke') }}</x-filament::button>@endif
        </div>
        @if($canManage && $draft)
            <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.350ms="search" :placeholder="__('campaign.search')" :aria-label="__('campaign.search')" /></x-filament::input.wrapper>
            @foreach($matches as $member)
                <div class="flex flex-wrap items-center gap-2" wire:key="match-{{ $member->id }}">
                    <span>{{ $member->first_name }} {{ $member->last_name }}</span>
                    <x-filament::button size="sm" wire:click="addRecipient({{ $member->id }})">{{ __('campaign.add') }}</x-filament::button>
                </div>
            @endforeach
        @endif
        <div class="cu-admin-journal">
        <table>
            <thead><tr>
                @foreach(['name' => __('Mitglied'), 'status' => __('Mitgliederstatus'), 'team' => __('Mannschaft'), 'email' => __('E-Mail'), 'channel' => __('campaign.channel'), 'delivery' => __('campaign.delivery')] as $key => $label)
                    <th scope="col" aria-sort="{{ $sort === $key ? ($descending ? 'descending' : 'ascending') : 'none' }}">
                        <button type="button" wire:click="sortBy('{{ $key }}')">{{ $label }} @if($sort === $key){{ $descending ? '↓' : '↑' }}@endif</button>
                        <x-filament::input.wrapper>
                            @if($key === 'channel' || $key === 'delivery')
                                <x-filament::input.select wire:model.live="filters.{{ $key }}" :aria-label="$label">
                                    <option value="">{{ __('Alle') }}</option>
                                    @foreach($key === 'channel' ? $channels : ['pending'=>__('campaign.pending'), 'queued'=>__('campaign.queued'), 'sending'=>__('campaign.sending'), 'accepted'=>__('campaign.accepted'), 'failed'=>__('campaign.failed'), 'attention'=>__('campaign.attention'), 'bounced'=>__('campaign.bounced')] as $value => $caption)
                                        <option value="{{ $value }}">{{ $caption }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            @else
                                <x-filament::input type="search" wire:model.live.debounce.350ms="filters.{{ $key }}" :aria-label="$label" maxlength="120" />
                            @endif
                        </x-filament::input.wrapper>
                    </th>
                @endforeach
            </tr></thead>
            <tbody>
            @foreach($recipients as $row)
                <tr wire:key="recipient-{{ $row->id }}">
                    <td><strong>{{ $row->first_name }} {{ $row->last_name }}</strong>
                        @if($canEditMembers)
                            <x-filament::icon-button icon="heroicon-o-pencil-square" :label="__('Mitglied bearbeiten')" :tooltip="__('Mitglied bearbeiten')" wire:click="mountAction('editMember', {member: {{ $row->member_id }}})" />
                        @endif
                    </td>
                    <td>{{ $row->member_status ?? '–' }}</td>
                    <td>{{ $row->teams ?? '–' }}</td>
                    <td>{{ $row->email ?? '–' }}</td>
                    <td><div class="flex flex-wrap items-center gap-2">
                    @if($canManage && $draft)
                        <x-filament::input.wrapper><x-filament::input.select :aria-label="__('campaign.channel')" wire:change="recipient({{ $row->member_id }}, $event.target.value)">
                            @foreach($channels as $key => $label)<option value="{{ $key }}" @selected($row->channel === $key)>{{ $label }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                        <x-filament::icon-button icon="heroicon-o-trash" color="danger" wire:click="recipient({{ $row->member_id }}, null)" :label="__('campaign.remove')" :tooltip="__('campaign.remove')" />
                    @else
                        <span>{{ $channels[$row->channel] }}</span>
                    @endif
                    </div></td>
                    <td><div class="flex flex-wrap items-center gap-2">
                    @if(in_array($row->channel, ['email','both'], true))
                        <x-filament::badge>{{ !$row->email ? __('campaign.no_email') : __(match($row->mail_state) {
                            'sending' => 'campaign.sending', 'accepted' => 'campaign.accepted', 'failed' => 'campaign.failed',
                            'attention' => 'campaign.attention', default => 'campaign.pending',
                            'queued' => 'campaign.queued', 'bounced' => 'campaign.bounced',
                        }) }}</x-filament::badge>
                        @if($canManage && $open && $pilot && $mailQueueEnabled && str_ends_with(strtolower((string) $row->email), '@example.invalid') && !$row->request_id && $row->mail_state === 'pending')
                            <x-filament::button size="sm" wire:click="testMail({{ $row->id }})">{{ __('campaign.enqueue_test') }}</x-filament::button>
                        @endif
                        @if($canManage && $pilot && $mailQueueEnabled && $row->mail_state === 'accepted' && $row->mail_address_hash)
                            <x-filament::button size="sm" wire:click="markBounce({{ $row->id }})" wire:confirm="{{ __('campaign.mark_bounce') }}?">{{ __('campaign.mark_bounce') }}</x-filament::button>
                        @endif
                    @endif
                    @if(in_array($row->channel, ['post','both'], true))
                        @if(!$row->street || !$row->postal_code || !$row->city)
                            <x-filament::badge color="warning">{{ __('campaign.no_post') }}</x-filament::badge>
                        @elseif($canManage && $open && !$row->request_id)
                            <x-filament::button size="sm" tag="a" :href="route('clubunio.campaign.letter', [$tenant->slug, $campaign->id, $row->id])">{{ __('campaign.letter') }}</x-filament::button>
                            @unless($row->posted_at)<x-filament::button size="sm" wire:click="markPosted({{ $row->id }})">{{ __('campaign.posted') }}</x-filament::button>@endunless
                        @endif
                        @if($row->posted_at)<x-filament::badge>{{ __('campaign.posted_state') }}</x-filament::badge>@endif
                    @endif
                    @if($row->request_state)<x-filament::badge>{{ __(match($row->request_state) {
                        'approved' => 'campaign.approved', 'rejected' => 'campaign.rejected',
                        'withdrawn' => 'campaign.withdrawn', default => 'campaign.pending',
                    }) }}</x-filament::badge>@endif
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @if($count > $limit && $limit < 5000)
            <x-filament::button wire:click="$set('limit', {{ min(5000, $limit + 50) }})">{{ __('campaign.more') }}</x-filament::button>
        @endif
    @endif
</x-filament-panels::page>
