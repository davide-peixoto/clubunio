@php
    $row = (object) $getRecord();
    $steps = [$row->id => $row->steps];
    $actions = [$row->id => $row->actions];
    $financialActions = [$row->id => $row->financialActions];
    $balances = [$row->kind.':'.($row->invoice_id ?? $row->expense_id) => $row->balance];
    $eligibleInvoices = $row->eligibleInvoice ? [$row->invoice_id] : [];
    $eligibleExpenses = $row->eligibleExpense ? [$row->expense_id] : [];
    $financial = collect($row->financialActions);
@endphp
<div class="space-y-2 max-w-md whitespace-normal">
    @if($row->http_status)<small> (HTTP {{ $row->http_status }})</small>@endif
    @if($row->kind === 'expense' && $row->state === 'rejected' && $row->http_status === 403 && !$row->attachment_id)
        <p>Dateiupload nicht berechtigt. Bexio-Verbindung mit Dateizugriff erneut bestätigen.</p>
    @endif
    <details><summary>Übertragungsprotokoll</summary><ul>
        @foreach($steps[$row->id] ?? [] as $step)<li>{{ \Carbon\CarbonImmutable::parse($step->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}:
            {{ ['prepare'=>'Vorbereitung','send'=>'Übertragung','upload'=>'PDF-Anhang','create'=>'Dokumentanlage','verify'=>'Rückprüfung','reconcile'=>'Abgleich','rebind'=>'Verbindung geprüft','resume'=>'Upload erneut freigegeben'][$step->operation] }} ·
            {{ ['ready'=>'Bereit','started'=>'Gestartet','accepted'=>'Bestätigt','rejected'=>'Abgelehnt','uncertain'=>'Unklar','found'=>'Gefunden','unresolved'=>'Nicht eindeutig'][$step->state] }}
            @if($step->http_status) ({{ $step->http_status }}) @endif</li>@endforeach
    </ul></details>
    @foreach($actions[$row->id] ?? [] as $action)
        <p>{{ $action->operation === 'issue' ? 'Bexio-Freigabe' : 'Stornierung' }}: {{ $action->state === 'accepted' ? 'Bestätigt' : 'Unklar – Wiederholung gesperrt' }}
        @if($action->after_status) · Bexio-Status {{ $action->after_status }} @endif
        @if($action->http_status) · HTTP {{ $action->http_status }} @endif</p>
    @endforeach
    @php($financial = collect($financialActions[$row->id] ?? []))
    @foreach($financial as $action)
        <p>{{ ['payment_partial'=>'Test-Teilzahlung','payment_full'=>'Test-Restzahlung','bill_book'=>'Spesen verbucht','bill_pay'=>'Manuelle Testzahlung'][$action->operation] }}:
            {{ ['running'=>'Gestartet','accepted'=>'Bestätigt','rejected'=>'Abgelehnt','uncertain'=>'Unklar – Wiederholung gesperrt'][$action->state] }}
            @if($action->amount_cents) · CHF {{ number_format($action->amount_cents / 100, 2, '.', "'") }} @endif
            @if($action->http_status) · HTTP {{ $action->http_status }} @endif</p>
    @endforeach
    @php($balance = $balances[$row->kind.':'.($row->invoice_id ?? $row->expense_id)] ?? null)
    @if($balance)
        <p>Bezahlt CHF {{ number_format($balance->paid_cents / 100, 2, '.', "'") }} · Gutschriften CHF {{ number_format($balance->credit_cents / 100, 2, '.', "'") }} · Rest CHF {{ number_format($balance->remaining_cents / 100, 2, '.', "'") }}</p>
    @endif
</div>
