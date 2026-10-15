<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\CreditNoteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
// Read only: fixed existing sandbox tax correction, never creates/allocates anything.
$note = CreditNote::where('tenant_id', $tenant->id)->whereKey(18)->where('reason', 'like', 'SANDBOX %')->firstOrFail();
$invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($note->invoice_id);
abort_unless((string) $invoice->bexio_invoice_id === '6', 409);
$remote = app(CreditNoteService::class)->remote($actor, $tenant, $invoice->id);
echo json_encode(['invoice_id' => $invoice->id, 'credit_note_id' => $note->id, 'local_cents' => $note->amount_cents,
    'original_tax' => $note->included_tax, 'status' => $note->status->code, 'provider_credit_cents' => $remote['balance']['credit_cents'],
    'allocations' => $remote['credit_allocations'], 'payer_verified' => ! empty($remote['contact_id'])], JSON_THROW_ON_ERROR).PHP_EOL;
