<x-filament-widgets::widget class="fi-wi-chart cu-template-chart">
    <x-filament::section :heading="$chart['heading']">
        <div class="cu-chart-mount" data-cu-dashboard-chart data-props="{{ json_encode($chart, JSON_THROW_ON_ERROR) }}">
            <div wire:ignore data-cu-chart-canvas></div>
            <details class="cu-chart-fallback" open>
                <summary>{{ $chart['details'] }}</summary>
                <div class="cu-chart-data-table"><table>
                    <caption class="sr-only">{{ $chart['heading'] }}</caption>
                    <thead><tr><th scope="col">{{ $chart['heading'] }}</th>@foreach ($chart['data']['datasets'] as $dataset)<th scope="col">{{ $dataset['label'] }}</th>@endforeach</tr></thead>
                    <tbody>@forelse ($chart['data']['labels'] as $index => $label)
                        <tr><th scope="row">{{ $label }}</th>@foreach ($chart['data']['datasets'] as $dataset)<td>{{ number_format((float) ($dataset['data'][$index] ?? 0), 2, app()->getLocale() === 'de' ? '.' : '.', "'") }}</td>@endforeach</tr>
                    @empty<tr><td>{{ $chart['empty'] }}</td></tr>@endforelse</tbody>
                </table></div>
            </details>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
