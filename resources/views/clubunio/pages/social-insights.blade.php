<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/clubunio-social-insights.css') }}?v=1">
    <div class="cu-admin-toolbar">
        <x-filament::button tag="a" :href="url('/social-media')" icon="heroicon-o-arrow-left" color="gray">{{ trans('social.title') }}</x-filament::button>
        <label>{{ trans('social_insights.platform') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="platform">
            @foreach(['all','instagram','facebook'] as $key)<option value="{{ $key }}">{{ trans('social_insights.'.$key) }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>{{ trans('social_insights.period') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="days">
            @foreach([7,30,90] as $period)<option value="{{ $period }}">{{ trans('social_insights.days',['days'=>$period]) }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    @unless($enabled)<p role="status">{{ trans('social_insights.setup') }}</p>@endunless
    <x-filament::section :heading="trans('social_insights.accounts')">
        @if($canManage)
        <div class="cu-admin-toolbar">
        @foreach(['instagram','facebook'] as $provider)
            <form method="POST" action="{{ route('clubunio.social-insights.start',$provider) }}">@csrf
                @foreach($context as $key=>$value)<input type="hidden" name="context[{{ $key }}]" value="{{ $value }}">@endforeach
                <x-filament::button type="submit" icon="heroicon-o-link" :disabled="!$enabled">{{ trans('social_insights.connect',['platform'=>trans('social_insights.'.$provider)]) }}</x-filament::button>
            </form>
        @endforeach
        </div>
        @endif
        @if($selection && $canManage)
            <h3>{{ trans('social_insights.choose') }}</h3>
            @foreach($selection['pages'] as $index=>$page)
                <x-filament::button wire:click="choose({{ $index }})" icon="heroicon-o-check">{{ $page['name'] ?? $page['id'] }}</x-filament::button>
            @endforeach
        @endif
        <div class="cu-admin-journal"><table><thead><tr>
            @foreach(['platform','account','status','followers','growth','updated','actions'] as $column)<th scope="col">{{ trans('social_insights.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
        @forelse($connections as $connection)
            <tr wire:key="connection-{{ $connection->id }}">
                <td>{{ trans('social_insights.'.$connection->platform) }}</td><td>{{ $connection->name }}</td>
                <td><x-filament::badge :color="!$connection->active ? 'gray' : (now()->gte($connection->expires_at) ? 'warning' : 'success')">{{ trans('social_insights.'.(!$connection->active?'disconnected':(now()->gte($connection->expires_at)?'expired':'connected'))) }}</x-filament::badge></td>
                <td>{{ $followers[$connection->id]['value'] ?? trans('social_insights.na') }}</td>
                <td>{{ $followers[$connection->id]['delta'] ?? trans('social_insights.na') }}</td>
                <td>{{ $connection->last_synced_at ?? trans('social_insights.never') }}</td>
                <td><div class="cu-admin-toolbar">
                    @if($connection->active && $canSync)<x-filament::icon-button icon="heroicon-o-arrow-path" :label="trans('social_insights.refresh')" wire:click="requestImport({{ $connection->id }})" :disabled="!$enabled" />@endif
                    @if($connection->active && $canManage)<x-filament::icon-button icon="heroicon-o-link-slash" :label="trans('social_insights.disconnect')" wire:click="mountAction('disconnect', { id: {{ $connection->id }} })" />@endif
                </div></td>
            </tr>
        @empty<tr><td colspan="7">{{ trans('social_insights.no_accounts') }}</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <dl class="cu-insights-metrics">
        <div><dt>{{ trans('social_insights.posts') }}</dt><dd>{{ $total }}</dd></div>
        @foreach($totals as $key=>$metric)
            <div><dt>{{ trans('social_insights.'.$key) }}</dt><dd>{{ $metric['value'] ?? trans('social_insights.na') }}</dd>
                <small>{{ trans('social_insights.coverage',['count'=>$metric['coverage'],'total'=>$total]) }}</small></div>
        @endforeach
    </dl>
    <p>{{ trans('social_insights.metric_basis') }}</p>
    <div class="cu-admin-toolbar">
        <label>{{ trans('social_insights.search') }}<x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.400ms="search" maxlength="100" /></x-filament::input.wrapper></label>
        <label>{{ trans('social_insights.sort') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="sort">
            @foreach(['date',...\App\ClubUnio\Services\SocialInsightsService::METRICS] as $key)<option value="{{ $key }}">{{ trans('social_insights.'.$key) }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    <div class="cu-admin-journal cu-insights-posts"><table><thead><tr>
        @foreach(['date','platform','post',...\App\ClubUnio\Services\SocialInsightsService::METRICS] as $column)<th scope="col">{{ trans('social_insights.'.$column) }}</th>@endforeach
    </tr></thead><tbody>
        @forelse($posts as $post)
            <tr wire:key="remote-post-{{ $post->id }}"><td>{{ $post->published_at }}</td><td>{{ trans('social_insights.'.$post->platform) }}<br>{{ $post->name }}</td>
                <td><span class="cu-insights-caption">{{ \Illuminate\Support\Str::limit($post->caption,220) }}</span><br>
                    @if($post->permalink)<x-filament::link :href="$post->permalink" target="_blank" rel="noopener noreferrer" icon="heroicon-o-arrow-top-right-on-square">{{ trans('social_insights.original') }}</x-filament::link>@endif
                    <small>{{ $post->media_type }}</small>
                </td>
                @foreach(\App\ClubUnio\Services\SocialInsightsService::METRICS as $key)<td>{{ $post->values[$key] ?? trans('social_insights.na') }}</td>@endforeach
            </tr>
        @empty<tr><td colspan="9">{{ trans('social_insights.no_posts') }}</td></tr>@endforelse
    </tbody></table></div>
    @if($posts->count()<$total && $limit<200)<x-filament::button wire:click="$set('limit', {{ min(200,$limit+25) }})" color="gray">{{ trans('social_insights.more') }}</x-filament::button>@endif
    <x-filament::section :heading="trans('social_insights.history')">
        <div class="cu-admin-journal"><table><thead><tr>
            @foreach(['date','account','status','error','attempts'] as $column)<th scope="col">{{ trans('social_insights.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
        @forelse($history as $item)
            <tr><td>{{ $item->updated_at }}</td><td>{{ $connections->firstWhere('id',$item->object_id)?->name ?? $item->object_id }}</td>
                <td>{{ trans('social_insights.states.'.$item->state) }}</td><td>{{ $item->error_code ? __(\App\ClubUnio\Services\SyncWorkService::ERRORS[$item->error_code] ?? 'social_insights.failed') : '-' }}</td><td>{{ $item->attempts }}</td></tr>
        @empty<tr><td colspan="5">{{ trans('social_insights.no_history') }}</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
