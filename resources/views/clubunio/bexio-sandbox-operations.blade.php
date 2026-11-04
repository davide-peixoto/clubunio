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
<div class="flex flex-wrap gap-2">
    @if($row->state === 'ready')
        <x-filament::button wire:click="send({{ $row->id }})" wire:confirm="{{ __('Den synthetischen Testbeleg jetzt wirklich in SVS Api Test anlegen? Keine Zahlung und kein E-Mail-Versand.') }}" wire:loading.attr="disabled" icon="heroicon-o-paper-airplane">{{ __('In Sandbox übertragen') }}</x-filament::button>
    @elseif($row->state !== 'accepted')
        <x-filament::button wire:click="reconcile({{ $row->id }})" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">{{ __('Ergebnis abgleichen') }}</x-filament::button>
        @if($row->kind === 'expense' && $row->state === 'rejected' && in_array($row->http_status,[401,403]) && !$row->attachment_id && !$row->remote_id)
            <x-filament::button wire:click="resumeUpload({{ $row->id }})" wire:confirm="{{ __('Nach erneuter Anmeldung nur den ausdrücklich abgelehnten Upload wieder freigeben?') }}" wire:loading.attr="disabled" icon="heroicon-o-lock-open">{{ __('Upload wieder freigeben') }}</x-filament::button>
        @endif
    @elseif($row->kind === 'invoice')
        <x-filament::button tag="a" href="{{ \App\ClubUnio\Resources\InvoiceResource::getUrl('view', ['record'=>$row->invoice_id]) }}" icon="heroicon-o-document-text">{{ __('Rechnung / PDF') }}</x-filament::button>
        @php($invoiceActions = collect($actions[$row->id] ?? []))
        @if($invoiceActions->where('state', '!=', 'accepted')->isEmpty())
            @if(!$invoiceActions->contains('operation', 'issue') && !$invoiceActions->contains('operation', 'cancel'))
                <x-filament::button wire:click="invoiceAction({{ $row->invoice_id }}, 'issue')" wire:confirm="{{ __('Testrechnung in Bexio verbindlich freigeben? Keine E-Mail und keine Zahlung.') }}" wire:loading.attr="disabled" icon="heroicon-o-check">{{ __('In Bexio freigeben') }}</x-filament::button>
            @endif
            @if($invoiceActions->contains('operation', 'issue') && !$invoiceActions->contains('operation', 'cancel'))
                <x-filament::button wire:click="invoiceAction({{ $row->invoice_id }}, 'cancel')" wire:confirm="{{ __('Diese unbezahlte Sandbox-Rechnung stornieren?') }}" wire:loading.attr="disabled" color="danger" icon="heroicon-o-x-circle">{{ __('Stornieren') }}</x-filament::button>
            @endif
        @endif
        <x-filament::button wire:click="invoiceResult({{ $row->invoice_id }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">{{ __('Rechnungsstatus prüfen') }}</x-filament::button>
    @endif
    @if($row->state === 'accepted')
        @if($row->kind === 'expense')
            <x-filament::button tag="a" href="{{ \App\ClubUnio\Resources\ExpenseResource::getUrl('view', ['record'=>$row->expense_id]) }}" icon="heroicon-o-document-text">{{ __('Spesen ansehen') }}</x-filament::button>
        @endif
        @if($financial->whereIn('state',['running','uncertain'])->isEmpty())
            @if($row->kind === 'invoice' && in_array($row->invoice_id, $eligibleInvoices) && collect($actions[$row->id] ?? [])->where('operation','issue')->where('state','accepted')->isNotEmpty() && !collect($actions[$row->id] ?? [])->contains('operation','cancel') && !$financial->contains('operation','payment_full'))
                @if(!$financial->contains('operation','payment_partial'))
                    <x-filament::button wire:click="financeAction('invoice', {{ $row->invoice_id }}, 'payment_partial')" wire:confirm="{{ __('Die Hälfte des Restbetrags als reinen Test-Zahlungseingang buchen?') }}" wire:loading.attr="disabled" icon="heroicon-o-receipt-percent">{{ __('Test-Teilzahlung buchen') }}</x-filament::button>
                @endif
                <x-filament::button wire:click="financeAction('invoice', {{ $row->invoice_id }}, 'payment_full')" wire:confirm="{{ __('Den offenen Rest als reinen Test-Zahlungseingang buchen?') }}" wire:loading.attr="disabled" icon="heroicon-o-check">{{ __('Test-Restzahlung buchen') }}</x-filament::button>
            @elseif($row->kind === 'expense' && in_array($row->expense_id, $eligibleExpenses))
                @if(!$financial->contains('operation','bill_book'))
                    <x-filament::button wire:click="financeAction('expense', {{ $row->expense_id }}, 'bill_book')" wire:confirm="{{ __('Die synthetischen Spesen in der Bexio-Sandbox verbuchen?') }}" wire:loading.attr="disabled" icon="heroicon-o-book-open">{{ __('Testspesen verbuchen') }}</x-filament::button>
                @elseif($financial->where('operation','bill_book')->where('state','accepted')->isNotEmpty() && !$financial->contains('operation','bill_pay'))
                    <x-filament::button wire:click="financeAction('expense', {{ $row->expense_id }}, 'bill_pay')" wire:confirm="{{ __('Eine manuelle Testzahlung verbuchen? Es wird kein Bankauftrag erstellt.') }}" wire:loading.attr="disabled" icon="heroicon-o-check">{{ __('Manuelle Testzahlung buchen') }}</x-filament::button>
                @endif
            @endif
        @endif
        <x-filament::button wire:click="financeResult('{{ $row->kind }}', {{ $row->invoice_id ?? $row->expense_id }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">{{ __('Finanzabgleich') }}</x-filament::button>
    @endif
</div>
