<div class="cu-transfer">
    <style>
        .cu-transfer { display:grid; gap:12px; font-size:14px; }
        .cu-transfer-scroll { max-height:24rem; overflow:auto; border:1px solid #d1d5db; }
        .cu-transfer table { width:100%; table-layout:fixed; border-collapse:collapse; text-align:left; }
        .cu-transfer th, .cu-transfer td { padding:8px; vertical-align:top; overflow-wrap:anywhere; border-bottom:1px solid #d1d5db; }
        .cu-transfer th { position:sticky; top:0; background:var(--fi-modal-background, #f3f4f6); color:#1f2937; }
        .cu-transfer th:first-child { width:22%; } .cu-transfer th:last-child { width:28%; }
        .cu-transfer small { display:block; margin-top:4px; font-size:12px; }
    </style>
    @if (!empty($plan))
        <p class="text-sm">{{ collect($plan['rows'])->where('state', 'neu')->count() }} neu · {{ collect($plan['rows'])->where('state', 'vorhanden')->count() }} vorhanden · {{ collect($plan['rows'])->where('state', 'blockiert')->count() }} blockiert</p>
        <p class="text-sm text-gray-500">Vorhandene Zuordnungen bleiben unverändert, auch inaktive. Keine Übernahme von Belegen, Rechnungen, Trainingszeiten oder Terminen. Blockierte Einträge müssen zuerst bereinigt werden.</p>
        <div class="cu-transfer-scroll">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Typ</th><th class="p-2">Zuordnung</th><th class="p-2">Ergebnis</th></tr></thead>
                <tbody>
                @foreach ($plan['rows'] as $row)
                    <tr><td>{{ $row['type'] }}</td><td>{{ $row['label'] }}</td><td><strong style="color:{{ $row['state'] === 'blockiert' ? '#b45309' : ($row['state'] === 'neu' ? '#2563eb' : '#64748b') }}">{{ $row['state'] }}</strong><small>{{ $row['reason'] }}</small></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-500">Zielsaison für die Vorschau auswählen.</p>
    @endif
</div>
