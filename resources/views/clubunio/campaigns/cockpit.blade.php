@if($dispatchPreview)
    <x-filament::section :heading="trans('campaign_ops.preview')">
        <dl class="cu-campaign-metrics">
            @foreach($dispatchPreview['counts'] as $key => $value)
                <div><dt>{{ trans('campaign_ops.metric_'.$key) }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
        <h3>{{ $dispatchPreview['copy']['subject'] }}</h3>
        <p class="cu-campaign-copy">{{ $dispatchPreview['copy']['body'] }}</p>
        @foreach($dispatchPreview['issues'] as $issue)
            <p role="alert">@if($issue['id'])#{{ $issue['id'] }}: @endif{{ trans('campaign_ops.issue_'.$issue['reason']) }}</p>
        @endforeach
    </x-filament::section>
@endif
@if($operations)
    <x-filament::section :heading="trans('campaign_ops.cockpit')">
        <dl class="cu-campaign-metrics">
            @foreach($operations['summary'] as $key => $value)
                <div><dt>{{ trans('campaign_ops.metric_'.$key) }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
            @foreach($operations['states'] as $key => $value)
                <div><dt>{{ trans('campaign_ops.state_'.$key) }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
        <p>{{ trans('campaign_ops.status_note') }}</p>
        @if($operations['dispatches']->isNotEmpty())
            <div class="cu-admin-journal"><table>
                <thead><tr>
                    @foreach(['recipient','sequence','state','attempts','last_attempt','outcome','actions'] as $key)<th scope="col">{{ trans('campaign_ops.'.$key) }}</th>@endforeach
                </tr></thead>
                <tbody>@foreach($operations['dispatches'] as $dispatch)
                    <tr wire:key="dispatch-{{ $dispatch->id }}">
                        <td>{{ $dispatch->recipient_label ?? '#'.$dispatch->recipient_id }}</td>
                        <td>{{ $dispatch->sequence }}</td><td><x-filament::badge>{{ trans('campaign_ops.state_'.$dispatch->state) }}</x-filament::badge></td>
                        <td>{{ $dispatch->attempts }}</td><td>{{ $dispatch->attempted_at ?? '–' }}</td>
                        <td>{{ $dispatch->outcome ? trans('campaign_ops.outcome_'.$dispatch->outcome) : '–' }}</td>
                        <td>@if($canManage && $open && in_array($dispatch->state, ['failed','attention'], true) && $dispatch->attempts < 5)
                            <x-filament::icon-button icon="heroicon-o-arrow-path" :label="trans('campaign_ops.retry')" :tooltip="trans('campaign_ops.retry')"
                                wire:click="mountAction('retryDispatch', {dispatch: '{{ $dispatch->id }}'})" />
                        @endif
                        @if($canManage && in_array($dispatch->state, ['accepted','delivered'], true))
                            <x-filament::icon-button icon="heroicon-o-envelope-open" :label="trans('campaign_ops.record_bounce')" :tooltip="trans('campaign_ops.record_bounce')"
                                wire:click="mountAction('recordBounce', {dispatch: '{{ $dispatch->id }}'})" />
                        @endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
        @if($operations['responses']->isNotEmpty())
            <h3>{{ trans('campaign_ops.responses') }}</h3>
            <div class="cu-admin-journal"><table><thead><tr>
                @foreach(['recipient','response','amount','review'] as $key)<th>{{ trans('campaign_ops.'.$key) }}</th>@endforeach
            </tr></thead><tbody>
                @foreach($operations['responses'] as $response)
                    <tr wire:key="response-{{ $response['id'] }}">
                        <td>{{ $response['label'] ?? '#'.$response['member_id'] }}</td>
                        <td>{{ trans('campaign_ops.answer_'.$response['data']['answer']) }}<br>{{ $response['data']['note'] ?? '' }}
                            @if(isset($response['data']['pledge']))
                                <details><summary>{{ trans('campaign_ops.sponsor_details') }}</summary><dl>
                                    @foreach(['sponsor_name','first_name','last_name','street','house_number','postal_code','city','country','email'] as $field)
                                        <dt>{{ trans('campaign_ops.fields.'.$field) }}</dt><dd>{{ $response['data']['pledge'][$field] ?? '' }}</dd>
                                    @endforeach
                                    <dt>{{ trans('campaign_ops.amount_type') }}</dt><dd>{{ trans('campaign_ops.'.$response['data']['pledge']['amount_type']) }}</dd>
                                    <dt>{{ trans('campaign_ops.cap') }}</dt><dd>{{ \App\ClubUnio\Services\SponsorRunMoney::format($response['data']['pledge']['cap_cents']) }}</dd>
                                </dl></details>
                            @endif
                        </td>
                        <td>{{ isset($response['data']['pledge']) ? AppClubUnioServicesSponsorRunMoney::format($response['data']['pledge']['amount_cents']) : '–' }}</td>
                        <td>@if(!$response['reviewed'] && $canManage)
                            <x-filament::button size="sm" wire:click="mountAction('reviewResponse', {recipient: {{ $response['id'] }}, approve: true})">{{ trans('campaign_ops.approve') }}</x-filament::button>
                            <x-filament::button size="sm" color="danger" wire:click="mountAction('reviewResponse', {recipient: {{ $response['id'] }}, approve: false})">{{ trans('campaign_ops.reject') }}</x-filament::button>
                        @else
                            {{ trans('campaign_ops.review_'.$response['reviewed']) }}
                            @if($response['object_id']) #{{ $response['object_id'] }}@endif
                        @endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @endif
        @if($operations['events']->isNotEmpty())
            <details><summary>{{ trans('campaign_ops.history') }}</summary>
                <ul>@foreach($operations['events'] as $event)
                    <li>{{ $event->created_at }} · {{ trans('campaign_ops.state_'.$event->state) }} · {{ $event->dispatch_id }}</li>
                @endforeach</ul>
            </details>
        @endif
        @if(max($operations['dispatch_total'], $operations['response_total']) > $operationsLimit && $operationsLimit < 1000)
            <x-filament::button color="gray" wire:click="$set('operationsLimit', {{ min(1000, $operationsLimit + 25) }})">{{ __('campaign.more') }}</x-filament::button>
        @endif
    </x-filament::section>
@endif
