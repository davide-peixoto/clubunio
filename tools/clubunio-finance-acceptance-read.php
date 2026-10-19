<?php

// Read-only business acceptance of known synthetic sandbox documents; no apply mode.
use App\ClubUnio\Models\{Account, CreditNote, CustomerCredit, Invoice, Tenant};
use App\ClubUnio\Services\CreditNoteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
    $tenant = Tenant::where('slug', 'sv-sissach')->sole();
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $assert = static function (bool $valid, string $message): void {
        if (! $valid) { throw new LogicException($message); }
    };
    $state = static fn () => hash('sha256', json_encode([
        $db->table('clubora.invoices')->where('tenant_id', $tenant->id)->whereIn('id', [8440, 8546, 9774])->orderBy('id')->get(),
        $db->table('clubora.credit_notes')->where('tenant_id', $tenant->id)->whereIn('id', [18, 107])->orderBy('id')->get(),
        $db->table('clubora.customer_credits')->where('tenant_id', $tenant->id)->where('id', 41)->get(),
        $db->table('clubora.customer_credit_allocations')->where('tenant_id', $tenant->id)->where('customer_credit_id', 41)->orderBy('id')->get(),
    ], JSON_THROW_ON_ERROR));
    $before = $state();
    foreach ([['local' => 8546, 'remote' => '9', 'reference' => 'GS-00004', 'total' => 1005, 'credit' => 1005, 'remaining' => 0],
        ['local' => 9774, 'remote' => '13', 'reference' => 'GS-00005', 'total' => 2000, 'credit' => 1000, 'remaining' => 1000]] as $case) {
        $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($case['local']);
        $assert((string) $invoice->bexio_invoice_id === $case['remote'], 'Fixture identity changed');
        // Existing service verifies sandbox connection, dispatch registry, recipient and taxes.
        $remote = app(CreditNoteService::class)->remote($actor, $tenant, $invoice->id);
        foreach (['total', 'credit', 'remaining'] as $key) {
            $assert((int) $remote['balance'][$key.'_cents'] === $case[$key], 'Provider balance changed: '.$case['remote'].'/'.$key);
        }
        $assert((int) $remote['balance']['cash_received_cents'] === 0, 'Unexpected cash payment');
        $allocations = collect($remote['credit_allocations'])->where('reference', $case['reference']);
        $assert($allocations->count() === 1 && (int) $allocations->sum('amount_cents') === $case['credit'], 'Provider allocation changed');
        if ($case['remote'] === '13') {
            $credit = CustomerCredit::where('tenant_id', $tenant->id)->where('external_reference', 'GS-00005')->sole();
            $assert((int) $credit->id === 41 && (int) $credit->credit_note_id === 18 && (int) $credit->amount_cents === 1000 && $credit->remaining_cents === 0, 'Local customer credit changed');
            $allocation = $credit->allocations()->where('invoice_id', $invoice->id)->sole();
            $assert((int) $allocation->amount_cents === 1000, 'Local allocation changed');
            $note = CreditNote::where('tenant_id', $tenant->id)->findOrFail(18);
            $assert($note->status->code === 'confirmed' && (string) $note->included_tax === '0.748933', 'Original tax/confirmation changed');
        }
        echo json_encode(['invoice' => $case['remote'], 'credit' => $case['reference'], 'total_cents' => $case['total'], 'credited_cents' => $case['credit'], 'remaining_cents' => $case['remaining'], 'cash_cents' => 0, 'verified' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    $source = app(CreditNoteService::class)->remote($actor, $tenant, 8440);
    $assert($source['remote_id'] === '6' && (int) $source['balance']['total_cents'] === 4025 && (int) $source['balance']['credit_cents'] === 0 && (int) $source['balance']['remaining_cents'] === 0, 'Original paid invoice changed');
    $assert($before === $state(), 'Business snapshots changed during read');
    echo "PASS: original invoice6 unchanged; business fingerprints unchanged; no provider business writes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Acceptance failed ('.get_class($e).'); inspect locally without publishing provider payloads.'.PHP_EOL);
    exit(1);
}
