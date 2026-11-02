<div class="cu-transfer">
    <style>
        .cu-transfer { display:grid; gap:12px; font-size:14px; }
        .cu-transfer-scroll { max-height:24rem; overflow:auto; }
        .cu-transfer th, .cu-transfer td { overflow-wrap:anywhere; }
        .cu-transfer th { position:sticky; top:0; }
        .cu-transfer th:first-child { width:22%; } .cu-transfer th:last-child { width:28%; }
        .cu-transfer small { display:block; margin-top:4px; font-size:12px; }
    </style>
    @if (!empty($plan))
        <p class="text-sm">{{ collect($plan['rows'])->where('state', 'neu')->count() }} neu · {{ collect($plan['rows'])->where('state', 'vorhanden')->count() }} vorhanden · {{ collect($plan['rows'])->where('state', 'blockiert')->count() }} blockiert</p>
        <p class="text-sm text-gray-500">Vorhandene Zuordnungen bleiben unverändert, auch inaktive. Keine Übernahme von Belegen, Rechnungen, Trainingszeiten oder Terminen. Blockierte Einträge müssen zuerst bereinigt werden.</p>
        <div class="cu-transfer-scroll">
            <table class="cu-admin-journal w-full text-left text-sm">
                <thead><tr><th>Typ</th><th>Zuordnung</th><th>Ergebnis</th></tr></thead>
                <tbody>
                @foreach ($plan['rows'] as $row)
                    <tr><td>{{ $row['type'] }}</td><td>{{ $row['label'] }}</td><td><x-filament::badge :color="$row['state'] === 'blockiert' ? 'warning' : ($row['state'] === 'neu' ? 'success' : 'gray')">{{ $row['state'] }}</x-filament::badge><small>{{ $row['reason'] }}</small></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-500">Zielsaison für die Vorschau auswählen.</p>
    @endif
</div>
