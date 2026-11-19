<section id="monitoring" class="platform-section">
    <h2>{{ trans('platform_metrics.monitoring') }}</h2>
    <p>{{ trans('platform_metrics.members') }} @include('clubunio.platform.member-count', ['count' => $metrics['members']])</p>
    <form method="post" action="{{ route('clubunio.platform.change', $tenant->id) }}" class="platform-form">
        @include('clubunio.platform.fields', ['operation' => 'license'])
        <label>{{ trans('platform_metrics.license') }}
            <select name="license_plan">
                <option value="">{{ trans('platform_metrics.plans.unassigned') }}</option>
                @foreach(config('clubunio-licenses') as $plan => $limit)
                    <option value="{{ $plan }}" @selected($tenant->license_plan === $plan)>{{ trans('platform_metrics.plans.'.$plan) }}@if($limit !== null) ({{ $limit }})@endif</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('Begründung') }}<input name="reason" minlength="10" maxlength="500" required></label>
        <div class="platform-actions"><button class="primary">{{ __('Speichern') }}</button></div>
    </form>
    <h3>{{ trans('platform_metrics.sync') }}</h3>
    <div class="table-scroll">
        <table>
            <thead><tr><th>{{ trans('platform_metrics.operation') }}</th><th>{{ trans('platform_metrics.errors_7') }}</th><th>{{ trans('platform_metrics.errors_30') }}</th><th>{{ trans('platform_metrics.last_success') }}</th></tr></thead>
            <tbody>
            @forelse($syncOperations as $operation)
                <tr>
                    <td>{{ __(\App\ClubUnio\Services\SyncWorkService::LABELS[$operation->operation] ?? 'platform_metrics.unknown_operation') }}</td>
                    <td>{{ $operation->errors_7 }}</td>
                    <td>{{ $operation->errors_30 }}</td>
                    <td>{{ $operation->last_success ? \Illuminate\Support\Carbon::parse($operation->last_success)->timezone('Europe/Zurich')->isoFormat('lll') : trans('platform_metrics.no_success') }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ trans('platform_metrics.no_sync') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
