<div wire:poll.30s.visible class="cu-daily-live">
    <a class="cu-hub-search-trigger" href="{{ \App\ClubUnio\Pages\NotificationCenter::getUrl() }}" aria-label="{{ trans('daily.unread_count',['count'=>$unread]) }}" title="{{ trans('daily.unread_count',['count'=>$unread]) }}"><x-filament::icon icon="heroicon-o-bell" /><span aria-hidden="true">{{ $unread }}</span></a>
    @if($planningChanged)<aside class="cu-daily-update" role="status"><span>{{ trans('daily.planning_changed') }}</span><button type="button" x-on:click="window.location.reload()">{{ trans('daily.reload') }}</button></aside>@endif
</div>
