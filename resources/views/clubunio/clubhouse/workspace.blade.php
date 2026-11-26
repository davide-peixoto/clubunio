<div class="cu-clubhouse">
    <link rel="stylesheet" href="{{ asset('css/clubunio-clubhouse.css') }}?v=1">
    @if($errors->any())<div role="alert" class="cu-ch-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <x-filament::tabs class="cu-ch-tabs" :label="trans('clubhouse.sections')">
        @foreach(['overview' => 'heroicon-o-building-storefront', 'hours' => 'heroicon-o-clock', 'menus' => 'heroicon-o-document-text', 'access' => 'heroicon-o-user-group'] as $key => $icon)
            @continue($key === 'access' && !$data['capabilities']['admin'])
            <x-filament::tabs.item :icon="$icon" :active="$tab === $key" wire:click="selectTab('{{ $key }}')" wire:loading.attr="disabled">{{ trans('clubhouse.'.$key) }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    @if($tab === 'overview')
        <label class="cu-ch-preview-channel">{{ trans('clubhouse.preview') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="previewChannel"><option value="web">{{ trans('clubhouse.website') }}</option><option value="app">{{ trans('clubhouse.app') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
        <section class="cu-ch-current" aria-label="{{ trans('clubhouse.current_state') }}">
            <div>@svg('heroicon-o-clock')<div><h2>{{ trans($overview['open_now'] ? 'clubhouse.open_now' : 'clubhouse.closed_now') }}</h2>
                @if($overview['open_until'])<p>{{ trans('clubhouse.open_until', ['time' => \Carbon\CarbonImmutable::parse($overview['open_until'])->format('H:i')]) }}</p>
                @elseif($overview['next_opening'])<p>{{ trans('clubhouse.next_opening', ['time' => \Carbon\CarbonImmutable::parse($overview['next_opening'])->locale(app()->getLocale())->isoFormat('ddd, DD.MM. HH:mm')]) }}</p>
                @else<p>{{ trans('clubhouse.no_next_opening', ['date' => $overview['next_opening_search_until']]) }}</p>@endif
            </div></div>
            <span class="cu-ch-state" data-open="{{ $overview['open_now'] ? 'true' : 'false' }}">{{ trans($overview['open_now'] ? 'clubhouse.open' : 'clubhouse.closed') }}</span>
        </section>
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.next_days') }}</h2>@if($data['capabilities']['hours_write']){{ $this->newOpeningAction }}@endif</header>
            @include('clubunio.clubhouse.opening-days', ['days' => array_slice($overview['days'], 0, 7)])
        </section>
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.current_menus') }}</h2>@if($data['capabilities']['menus_write']){{ $this->newMenuAction }}@endif</header>
            @include('clubunio.clubhouse.published-menus', ['publishedMenus' => $overview['menus'], 'publicView' => false])
        </section>
    @elseif($tab === 'hours')
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.hours') }}</h2><div class="cu-ch-actions">@if($data['capabilities']['hours_write']){{ $this->newOpeningAction }}{{ $this->exceptionAction }}@endif</div></header>
            <div class="cu-ch-table" role="region" aria-label="{{ trans('clubhouse.hours') }}" tabindex="0">
                <table><thead><tr><th>{{ trans('clubhouse.label') }}</th><th>{{ trans('clubhouse.repeat_days') }}</th><th>{{ trans('clubhouse.validity') }}</th><th>{{ trans('clubhouse.time_windows') }}</th><th>{{ trans('clubhouse.state') }}</th><th>{{ trans('clubhouse.actions') }}</th></tr></thead><tbody>
                @forelse($data['rules'] as $rule)
                    <tr wire:key="clubhouse-opening-{{ $rule['id'] }}"><td>{{ $rule['title'] }}</td>
                        <td>{{ $rule['weekdays'] ? implode(', ', array_map(fn($day) => trans('clubhouse.weekday_'.$day), $rule['weekdays'])) : trans('clubhouse.once') }}</td>
                        <td>{{ $rule['starts_on'] }}<br>{{ $rule['ends_on'] ?? trans('clubhouse.no_end') }}</td>
                        <td>@foreach($rule['intervals'] as $slot)<span class="cu-ch-slot">{{ $slot['start'] }} – {{ $slot['end'] }}{{ $slot['overnight'] ? ' (+1)' : '' }}</span>@endforeach</td>
                        <td><x-filament::badge :color="$rule['active'] ? 'success' : 'gray'">{{ trans($rule['active'] ? 'clubhouse.active' : 'clubhouse.inactive') }}</x-filament::badge></td>
                        <td>@if($data['capabilities']['hours_write']){{ ($this->editOpeningAction)(['id' => $rule['id']]) }}@endif</td>
                    </tr>
                @empty<tr><td colspan="6" class="cu-ch-empty">{{ trans('clubhouse.no_openings') }}</td></tr>@endforelse
                </tbody></table>
            </div>
        </section>
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.exceptions') }}</h2></header>
            <ul class="cu-ch-exceptions">@forelse($data['exceptions'] as $exception)
                <li wire:key="clubhouse-exception-{{ $exception['id'] }}"><div><strong>{{ $exception['date'] }}</strong><span>{{ trans($exception['rule_id'] ? 'clubhouse.series_exception' : 'clubhouse.whole_day_exception') }}</span>
                    <p>{{ $exception['closed'] ? trans('clubhouse.closed') : implode(', ', array_map(fn($x) => $x['start'].' – '.$x['end'].($x['overnight'] ? ' (+1)' : ''), $exception['intervals'])) }}</p>
                    @if($exception['note'])<small>{{ $exception['note'] }}</small>@endif</div><div class="cu-ch-actions">
                    @if($data['capabilities']['hours_write'])
                        @if(!$exception['rule_id']){{ ($this->exceptionAction)(['id' => $exception['id']]) }}@endif
                        {{ ($this->removeExceptionAction)(['id' => $exception['id']]) }}
                    @endif
                </div></li>
            @empty<li class="cu-ch-empty">{{ trans('clubhouse.no_exceptions') }}</li>@endforelse</ul>
        </section>
    @elseif($tab === 'menus')
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.menus') }}</h2>@if($data['capabilities']['menus_write']){{ $this->newMenuAction }}@endif</header>
            <div class="cu-ch-filters"><label>{{ trans('clubhouse.search') }}<x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.350ms="menuSearch" /></x-filament::input.wrapper></label>
                <label>{{ trans('clubhouse.state') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="menuStatus"><option value="all">{{ trans('clubhouse.all') }}</option>@foreach($data['statuses'] as $status)<option value="{{ $status['code'] }}">{{ $status['label'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label></div>
            <ul class="cu-ch-menu-list">@forelse($menus as $menu)
                @php($appearance = collect($data['statuses'])->firstWhere('code', $menu['status']))
                <li wire:key="clubhouse-menu-{{ $menu['id'] }}">
                    <header><div><h3>{{ (app()->getLocale() === 'en' ? $menu['title_en'] : null) ?: $menu['title_de'] }}</h3><p>{{ $menu['valid_from'] }} – {{ $menu['valid_until'] }}</p></div>
                        <x-filament::badge :color="$appearance['color'] ?? 'gray'">{{ $appearance['label'] ?? $menu['status'] }}</x-filament::badge></header>
                    @if($menu['is_live'] && $menu['status'] === 'draft')<p class="cu-ch-published-note">@svg('heroicon-o-information-circle') {{ trans('clubhouse.published_copy_kept') }}</p>@endif
                    <div class="cu-ch-actions">
                        @if($data['capabilities']['menus_write'] && $menu['status'] !== 'archived'){{ ($this->editMenuAction)(['id' => $menu['id']]) }}{{ ($this->uploadMenuAction)(['id' => $menu['id']]) }}@endif
                        @if($data['capabilities']['menus_publish'] && $menu['status'] !== 'archived'){{ ($this->publishAction)(['id' => $menu['id']]) }}@endif
                        @if($data['capabilities']['menus_publish'] && $menu['is_live']){{ ($this->withdrawAction)(['id' => $menu['id']]) }}@endif
                        @if($data['capabilities']['menus_write'])
                            @if($menu['status'] === 'archived'){{ ($this->restoreAction)(['id' => $menu['id']]) }}
                            @elseif(!$menu['is_live'] || $data['capabilities']['menus_publish']){{ ($this->archiveAction)(['id' => $menu['id']]) }}@endif
                        @endif
                        @if($menu['has_document'])<a class="cu-ch-link" href="{{ $guard === 'portal' ? route('clubunio.portal.clubhouse.pdf', ['tenant' => $tenant->slug, 'menu' => $menu['id']]) : route('clubunio.clubhouse.pdf', ['menu' => $menu['id']]) }}" target="_blank" rel="noopener">@svg('heroicon-o-document-arrow-down') {{ trans('clubhouse.open_pdf') }}</a>@if($data['capabilities']['menus_write'] && $menu['status'] !== 'archived'){{ ($this->removePdfAction)(['id' => $menu['id']]) }}@endif @endif
                    </div>
                    <details class="cu-ch-preview"><summary>@svg('heroicon-o-eye') {{ trans('clubhouse.preview') }}</summary>
                        <p class="cu-ch-menu-text">{{ (app()->getLocale() === 'en' ? $menu['body_en'] : null) ?: $menu['body_de'] }}</p>
                        <small>{{ implode(' · ', array_map(fn($channel) => trans('clubhouse.'.($channel === 'web' ? 'website' : 'app')), $menu['channels'])) }}</small>
                    </details>
                </li>
            @empty<li class="cu-ch-empty">{{ trans('clubhouse.no_menus') }}</li>@endforelse</ul>
            <div class="cu-ch-pagination"><x-filament::icon-button icon="heroicon-o-chevron-left" :label="trans('clubhouse.previous')" :tooltip="trans('clubhouse.previous')" wire:click="$set('menuPage', {{ max(1, $menuPage - 1) }})" :disabled="$menuPage <= 1" wire:loading.attr="disabled" /><span>{{ trans('clubhouse.page', ['page' => $menuPage, 'total' => $data['pagination']['total']]) }}</span><x-filament::icon-button icon="heroicon-o-chevron-right" :label="trans('clubhouse.next')" :tooltip="trans('clubhouse.next')" wire:click="$set('menuPage', {{ $menuPage + 1 }})" :disabled="!$data['pagination']['has_more']" wire:loading.attr="disabled" /></div>
        </section>
    @elseif($tab === 'access')
        <section class="cu-ch-band">
            <header><h2>{{ trans('clubhouse.additional_role') }}</h2>{{ $this->grantAction }}</header>
            <ul class="cu-ch-exceptions">@forelse($grants as $grant)<li><div><strong>{{ $grant->display_name }}</strong><p>{{ implode(' · ', array_filter([$grant->hours_write ? trans('clubhouse.hours') : null, $grant->menus_write ? trans('clubhouse.menus') : null, $grant->menus_publish ? trans('clubhouse.publish') : null])) }}</p></div><x-filament::badge :color="$grant->active ? 'success' : 'gray'">{{ trans($grant->active ? 'clubhouse.active' : 'clubhouse.inactive') }}</x-filament::badge></li>@empty<li class="cu-ch-empty">{{ trans('clubhouse.no_grants') }}</li>@endforelse</ul>
        </section>
        <section class="cu-ch-band"><header><h2>{{ trans('clubhouse.publication') }}</h2>{{ $this->settingsAction }}</header>
            <dl class="cu-ch-settings"><div><dt>{{ trans('clubhouse.public_enabled') }}</dt><dd>{{ trans($data['settings']['public_enabled'] ? 'clubhouse.yes' : 'clubhouse.no') }}</dd></div><div><dt>{{ trans('clubhouse.timezone') }}</dt><dd>{{ $data['settings']['timezone'] }}</dd></div></dl>
            @if($data['settings']['public_enabled'])<div class="cu-ch-actions"><a class="cu-ch-link" href="{{ route('clubunio.clubhouse.public', ['tenant' => $tenant->slug]) }}" target="_blank" rel="noopener">@svg('heroicon-o-arrow-top-right-on-square') {{ trans('clubhouse.public_preview') }}</a><a class="cu-ch-link" href="{{ route('clubunio.clubhouse.public.data', ['tenant' => $tenant->slug]) }}" target="_blank" rel="noopener">@svg('heroicon-o-code-bracket') {{ trans('clubhouse.website_feed') }}</a></div>@endif
        </section>
    @endif
    <x-filament-actions::modals />
</div>
