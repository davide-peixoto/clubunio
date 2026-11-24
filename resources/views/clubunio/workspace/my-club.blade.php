<x-filament-panels::page>
<div class="cu-workflow">
    <p class="cu-module-note">{{ trans('workspace.intro') }}</p>
    <section class="cu-workflow-section"><h2>{{ trans('workspace.quick') }}</h2><div class="cu-workflow-cards">
        @foreach($quick as $item)<a class="cu-workflow-card" href="{{ $item['url'] }}"><x-filament::icon :icon="$item['icon']" /><span>{{ $item['label'] }}</span></a>@endforeach
    </div></section>
    <section class="cu-workflow-section"><h2>{{ trans('workspace.tasks') }}</h2><div class="cu-workflow-cards">
        @forelse($queues as $kind => $queue)<a class="cu-workflow-card" href="{{ \App\ClubUnio\Pages\ApprovalCenter::getUrl(['kind'=>$kind]) }}"><span>{{ $queue['label'] }}</span><strong>{{ $queue['count'] }}</strong></a>@empty<p>{{ trans('workspace.empty') }}</p>@endforelse
    </div></section>
    <div class="cu-workflow-columns">
        <section class="cu-workflow-section"><h2>{{ trans('workspace.next_week') }}</h2><ul class="cu-workflow-list">
            @forelse($upcoming as $event)<li><time>{{ \Carbon\CarbonImmutable::parse($event->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</time><a href="{{ route('clubunio.planning.show',[$tenant->slug,$event->id]) }}">{{ $event->title }}</a></li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse
        </ul></section>
        <section class="cu-workflow-section"><h2>{{ trans('workspace.favorites') }}</h2><p class="cu-module-note">{{ trans('workspace.favorites_note') }}</p><ul class="cu-workflow-list">
            @forelse(array_intersect_key($links,array_flip($favorites)) as $key=>$link)<li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse
        </ul></section>
    </div>
    <details class="cu-workflow-section" wire:ignore.self><summary>{{ trans('workspace.modules') }}</summary><div class="cu-workflow-cards">
        @foreach($links as $key=>$link)<div class="cu-workflow-card"><a href="{{ $link['url'] }}">{{ $link['label'] }}</a><button type="button" wire:click="toggleFavorite('{{ $key }}')" wire:loading.attr="disabled" aria-label="{{ trans('workspace.favorite_toggle',['name'=>$link['label']]) }}" aria-pressed="{{ in_array($key,$favorites,true) ? 'true' : 'false' }}"><x-filament::icon :icon="in_array($key,$favorites,true) ? 'heroicon-s-star' : 'heroicon-o-star'" /></button></div>@endforeach
    </div></details>
    <div class="cu-workflow-columns">
        <section class="cu-workflow-section"><div class="cu-workflow-heading"><h2>{{ trans('workspace.recent') }}</h2><x-filament::button color="gray" size="sm" wire:click="clearRecent" wire:loading.attr="disabled">{{ trans('workspace.clear_recent') }}</x-filament::button></div>
            <p class="cu-module-note">{{ trans('workspace.recent_note') }}</p><ul class="cu-workflow-list">@forelse($recent as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse</ul>
        </section>
        <section class="cu-workflow-section"><h2>{{ trans('workspace.saved_views') }}</h2><p class="cu-module-note">{{ trans('workspace.views_note') }}</p><ul class="cu-workflow-list">
            @forelse($views as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a><span>{{ $item['list'] }}</span></li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse
        </ul>
        @if($viewsCount>20)<div class="cu-workflow-pagination"><x-filament::button color="gray" :disabled="$viewsPage<=1" wire:click="$set('viewsPage',{{ max(1,$viewsPage-1) }})">{{ trans('workspace.previous') }}</x-filament::button><span>{{ trans('workspace.page',['page'=>$viewsPage]) }}</span><x-filament::button color="gray" :disabled="$viewsPage*20 >= $viewsCount" wire:click="$set('viewsPage',{{ $viewsPage+1 }})">{{ trans('workspace.next') }}</x-filament::button></div>@endif
        </section>
    </div>
</div>
</x-filament-panels::page>
