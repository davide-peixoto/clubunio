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
    @if($row->http_status)<small> {{ __('(HTTP') }} {{ $row->http_status }})</small>@endif
    @if($row->kind === 'expense' && $row->state === 'rejected' && $row->http_status === 403 && !$row->attachment_id)
        <p>{{ __('Dateiupload nicht berechtigt. Bexio-Verbindung mit Dateizugriff erneut bestätigen.') }}</p>
    @endif
    <details><summary>{{ __('Übertragungsprotokoll') }}</summary><ul>
        @foreach($steps[$row->id] ?? [] as $step)<li>{{ \Carbon\CarbonImmutable::parse($step->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}:
            {{ ['prepare'=>__('Vorbereitung'),'send'=>__('Übertragung'),'upload'=>__('PDF-Anhang'),'create'=>__('Dokumentanlage'),'verify'=>__('Rückprüfung'),'reconcile'=>__('Abgleich'),'rebind'=>__('Verbindung geprüft'),'resume'=>__('Upload erneut freigegeben')][$step->operation] }} ·
            {{ ['ready'=>__('Bereit'),'started'=>__('Gestartet'),'accepted'=>__('Bestätigt'),'rejected'=>__('Abgelehnt'),'uncertain'=>__('Unklar'),'found'=>__('Gefunden'),'unresolved'=>__('Nicht eindeutig')][$step->state] }}
            @if($step->http_status) ({{ $step->http_status }}) @endif</li>@endforeach
    </ul></details>
    @foreach($actions[$row->id] ?? [] as $action)
        <p>{{ $action->operation === 'issue' ? __('Bexio-Freigabe') : __('Stornierung') }}: {{ $action->state === 'accepted' ? __('Bestätigt') : __('Unklar – Wiederholung gesperrt') }}
        @if($action->after_status) {{ __('· Bexio-Status') }} {{ $action->after_status }} @endif
        @if($action->http_status) {{ __('· HTTP') }} {{ $action->http_status }} @endif</p>
    @endforeach
    @php($financial = collect($financialActions[$row->id] ?? []))
    @foreach($financial as $action)
        <p>{{ ['payment_partial'=>__('Test-Teilzahlung'),'payment_full'=>__('Test-Restzahlung'),'bill_book'=>__('Spesen verbucht'),'bill_pay'=>__('Manuelle Testzahlung')][$action->operation] }}:
            {{ ['running'=>__('Gestartet'),'accepted'=>__('Bestätigt'),'rejected'=>__('Abgelehnt'),'uncertain'=>__('Unklar – Wiederholung gesperrt')][$action->state] }}
            @if($action->amount_cents) {{ __('· CHF') }} {{ number_format($action->amount_cents / 100, 2, '.', "'") }} @endif
            @if($action->http_status) {{ __('· HTTP') }} {{ $action->http_status }} @endif</p>
    @endforeach
    @php($balance = $balances[$row->kind.':'.($row->invoice_id ?? $row->expense_id)] ?? null)
    @if($balance)
        <p>{{ __('Bezahlt CHF') }} {{ number_format($balance->paid_cents / 100, 2, '.', "'") }} {{ __('· Gutschriften CHF') }} {{ number_format($balance->credit_cents / 100, 2, '.', "'") }} {{ __('· Rest CHF') }} {{ number_format($balance->remaining_cents / 100, 2, '.', "'") }}</p>
    @endif
</div>
