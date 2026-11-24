@if(auth('clubunio')->check() && \App\ClubUnio\Pages\ObjectSearch::canAccess())
<a class="cu-hub-search-trigger" data-object-search-link href="{{ \App\ClubUnio\Pages\ObjectSearch::getUrl() }}" aria-label="{{ trans('work_hub.search') }}" title="{{ trans('work_hub.search') }} · Ctrl/⌘+Shift+F"><x-filament::icon icon="heroicon-o-command-line" /><span>{{ trans('work_hub.search') }}</span></a>
@livewire(\App\ClubUnio\Pages\Components\WorkLiveStatus::class)
@endif
