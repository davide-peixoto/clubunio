<div class="cu-admin-native">
    @if($profileAllows('sync') || \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'competitions', 'sync'))
        <form class="mb-4" method="post" action="{{ route('clubunio.workspace.planning.sfv.refresh') }}">
            @csrf
            <input type="hidden" name="_workspace" value="{{ $workspaceToken }}">
            <input type="hidden" name="from" value="{{ $filters['from'] ?? '' }}"><input type="hidden" name="to" value="{{ $filters['to'] ?? '' }}">
            <x-filament::button type="submit" icon="heroicon-o-arrow-path">{{ trans(\App\ClubUnio\Resources\ProfileUi::allows('competitions','sync')?'daily.sfv_sync':'daily.sfv_fetch') }}</x-filament::button>
        </form>
    @endif
    @if(session('sfv_reconcile.issues'))<aside><h2>{{ trans('daily.needs_review') }}</h2>@foreach(session('sfv_reconcile.issues') as $issue)<p><strong>{{ $issue['title'] }}</strong> · {{ $issue['message'] }}</p>@endforeach<a href="{{ \App\ClubUnio\Pages\SfvCompetitions::getUrl() }}">{{ trans('daily.review_game') }} ↗</a></aside>@endif
    {{ $this->table }}
</div>
