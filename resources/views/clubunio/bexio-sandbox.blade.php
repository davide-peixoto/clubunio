<x-filament-panels::page>
    <style>
        .sandbox-scroll { overflow-x: auto; }
        .sandbox-table { width: 100%; min-width: 680px; border-collapse: collapse; font-size: 14px; text-align: left; }
        .sandbox-table th, .sandbox-table td { padding: 16px 12px; vertical-align: top; text-align: left; border-bottom: 1px solid #d1d5db; }
        .sandbox-table th { font-weight: 600; }
        .sandbox-table td:last-child { min-width: 220px; }
        .sandbox-table td:last-child > * { margin: 0 6px 6px 0; }
        .sandbox-table details { margin-top: 8px; }
        .sandbox-table small { display: inline-block; margin-top: 4px; }
        .sandbox-table p { max-width: 440px; margin-top: 8px; }
        .sandbox-table li { margin-top: 6px; }
    </style>
    <p role="status"><strong>SVS Api Test · Testbuchungen · Keine Bankaufträge, kein E-Mail-Versand</strong></p>
    <a href="{{ route('clubunio.bexio.show', ['tenant' => \Filament\Facades\Filament::getTenant()->slug]) }}">Bexio-Verbindung</a>
    <a href="{{ \App\ClubUnio\Pages\BexioDispatchTrials::getUrl() }}">Allgemeine Versandprüfung</a>
    <a href="{{ \App\ClubUnio\Pages\BexioReconciliation::getUrl() }}">Statusabgleich</a>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="prepare('invoice')" wire:loading.attr="disabled" icon="heroicon-o-document-plus">Testrechnung · CHF 25</x-filament::button>
        <x-filament::button wire:click="prepare('expense')" wire:loading.attr="disabled" icon="heroicon-o-receipt-percent">Testspesen · CHF 15</x-filament::button>
        <x-filament::button wire:click="prepareParent" wire:confirm="Synthetische Elternrechnung mit CHF 7 Rabatt vorbereiten und lokal freigeben?" wire:loading.attr="disabled" icon="heroicon-o-user-group">Eltern-Testrechnung · CHF 33</x-filament::button>
        <x-filament::button wire:click="verifyReconnection" wire:confirm="Die erneut angemeldete Sandbox anhand der bestehenden Testbelege prüfen und zuordnen?" wire:loading.attr="disabled" color="gray" icon="heroicon-o-shield-check">Neue Verbindung prüfen</x-filament::button>
    </div>
    <div class="sandbox-scroll"><table class="sandbox-table">
        <thead><tr><th class="p-3">Testbeleg</th><th class="p-3">Status</th><th class="p-3">Bexio-ID</th><th class="p-3">Aktion</th></tr></thead>
        <tbody>@forelse($rows as $row)
            <tr class="border-b">
                <td class="p-3">{{ $row->kind === 'invoice' ? 'Rechnung' : 'Spesen' }} #{{ $row->invoice_id ?? $row->expense_id }}<br>
                    <small>CHF {{ number_format(($row->expected_cents ?? ($row->kind === 'invoice' ? 2500 : 1500)) / 100, 2, '.', "'") }} · {{ $row->source_trial_id ? 'Allgemeine Versandprüfung' : 'Fester Testbeleg' }}</small></td>
                <td class="p-3">{{ ['ready'=>'Bereit','running'=>'Gestartet','accepted'=>'Angelegt und geprüft','rejected'=>'Abgelehnt','uncertain'=>'Unklar – Wiederholung gesperrt'][$row->state] }}
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
                </td>
                <td class="p-3 break-all">{{ $row->remote_id ?? '–' }}</td>
                <td class="p-3">
                    @if($row->state === 'ready')
                        <x-filament::button wire:click="send({{ $row->id }})" wire:confirm="Den synthetischen Testbeleg jetzt wirklich in SVS Api Test anlegen? Keine Zahlung und kein E-Mail-Versand." wire:loading.attr="disabled" icon="heroicon-o-paper-airplane">In Sandbox übertragen</x-filament::button>
                    @elseif($row->state !== 'accepted')
                        <x-filament::button wire:click="reconcile({{ $row->id }})" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">Ergebnis abgleichen</x-filament::button>
                        @if($row->kind === 'expense' && $row->state === 'rejected' && in_array($row->http_status,[401,403]) && !$row->attachment_id && !$row->remote_id)
                            <x-filament::button wire:click="resumeUpload({{ $row->id }})" wire:confirm="Nach erneuter Anmeldung nur den ausdrücklich abgelehnten Upload wieder freigeben?" wire:loading.attr="disabled" icon="heroicon-o-lock-open">Upload wieder freigeben</x-filament::button>
                        @endif
                    @elseif($row->kind === 'invoice')
                        <x-filament::button tag="a" href="{{ \App\ClubUnio\Resources\InvoiceResource::getUrl('view', ['record'=>$row->invoice_id]) }}" icon="heroicon-o-document-text">Rechnung / PDF</x-filament::button>
                        @php($invoiceActions = collect($actions[$row->id] ?? []))
                        @if($invoiceActions->where('state', '!=', 'accepted')->isEmpty())
                            @if(!$invoiceActions->contains('operation', 'issue') && !$invoiceActions->contains('operation', 'cancel'))
                                <x-filament::button wire:click="invoiceAction({{ $row->invoice_id }}, 'issue')" wire:confirm="Testrechnung in Bexio verbindlich freigeben? Keine E-Mail und keine Zahlung." wire:loading.attr="disabled" icon="heroicon-o-check">In Bexio freigeben</x-filament::button>
                            @endif
                            @if($invoiceActions->contains('operation', 'issue') && !$invoiceActions->contains('operation', 'cancel'))
                                <x-filament::button wire:click="invoiceAction({{ $row->invoice_id }}, 'cancel')" wire:confirm="Diese unbezahlte Sandbox-Rechnung stornieren?" wire:loading.attr="disabled" color="danger" icon="heroicon-o-x-circle">Stornieren</x-filament::button>
                            @endif
                        @endif
                        <x-filament::button wire:click="invoiceResult({{ $row->invoice_id }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Rechnungsstatus prüfen</x-filament::button>
                    @endif
                    @if($row->state === 'accepted')
                        @if($row->kind === 'expense')
                            <x-filament::button tag="a" href="{{ \App\ClubUnio\Resources\ExpenseResource::getUrl('view', ['record'=>$row->expense_id]) }}" icon="heroicon-o-document-text">Spesen ansehen</x-filament::button>
                        @endif
                        @if($financial->whereIn('state',['running','uncertain'])->isEmpty())
                            @if($row->kind === 'invoice' && in_array($row->invoice_id, $eligibleInvoices) && collect($actions[$row->id] ?? [])->where('operation','issue')->where('state','accepted')->isNotEmpty() && !collect($actions[$row->id] ?? [])->contains('operation','cancel') && !$financial->contains('operation','payment_full'))
                                @if(!$financial->contains('operation','payment_partial'))
                                    <x-filament::button wire:click="financeAction('invoice', {{ $row->invoice_id }}, 'payment_partial')" wire:confirm="Die Hälfte des Restbetrags als reinen Test-Zahlungseingang buchen?" wire:loading.attr="disabled" icon="heroicon-o-receipt-percent">Test-Teilzahlung buchen</x-filament::button>
                                @endif
                                <x-filament::button wire:click="financeAction('invoice', {{ $row->invoice_id }}, 'payment_full')" wire:confirm="Den offenen Rest als reinen Test-Zahlungseingang buchen?" wire:loading.attr="disabled" icon="heroicon-o-check">Test-Restzahlung buchen</x-filament::button>
                            @elseif($row->kind === 'expense' && in_array($row->expense_id, $eligibleExpenses))
                                @if(!$financial->contains('operation','bill_book'))
                                    <x-filament::button wire:click="financeAction('expense', {{ $row->expense_id }}, 'bill_book')" wire:confirm="Die synthetischen Spesen in der Bexio-Sandbox verbuchen?" wire:loading.attr="disabled" icon="heroicon-o-book-open">Testspesen verbuchen</x-filament::button>
                                @elseif($financial->where('operation','bill_book')->where('state','accepted')->isNotEmpty() && !$financial->contains('operation','bill_pay'))
                                    <x-filament::button wire:click="financeAction('expense', {{ $row->expense_id }}, 'bill_pay')" wire:confirm="Eine manuelle Testzahlung verbuchen? Es wird kein Bankauftrag erstellt." wire:loading.attr="disabled" icon="heroicon-o-check">Manuelle Testzahlung buchen</x-filament::button>
                                @endif
                            @endif
                        @endif
                        <x-filament::button wire:click="financeResult('{{ $row->kind }}', {{ $row->invoice_id ?? $row->expense_id }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Finanzabgleich</x-filament::button>
                    @endif
                </td>
            </tr>
        @empty<tr><td colspan="4" class="p-3">Noch keine Web-Testbelege vorbereitet.</td></tr>@endforelse</tbody>
    </table></div>
</x-filament-panels::page>
