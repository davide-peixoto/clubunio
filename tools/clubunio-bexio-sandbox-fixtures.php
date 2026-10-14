<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected database');
}
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$apply = in_array('--create-contacts', $argv, true) || in_array('--create-invoice', $argv, true);
$lock = fopen(storage_path('framework/clubora-sandbox-fixtures.lock'), 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another fixture process is running');
}
try {
    $token = app(BexioConnectionService::class)->accessToken($tenant);
    $request = fn () => Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    $get = function (string $path, array $query = []) use ($request) {
        $r = $request()->get('https://api.bexio.com'.$path, $query);
        if (! $r->successful() || ! is_array($r->json())) {
            throw new RuntimeException('Read failed: '.$path, $r->status());
        }

        return $r->json();
    };
    $profiles = $get('/2.0/company_profile');
    if (count($profiles) !== 1 || ($profiles[0]['name'] ?? '') !== 'SVS Api Test' || (int) ($profiles[0]['id'] ?? 0) !== 1) {
        throw new RuntimeException('Sandbox identity mismatch', 409);
    }
    echo 'Verified sandbox: SVS Api Test / profile 1'.PHP_EOL;
    $me = $get('/3.0/users/me');
    $user = $me['id'] ?? null;
    if (! is_numeric($user) || $user < 1) {
        throw new RuntimeException('Missing sandbox user', 422);
    }
    echo 'Sandbox user id: '.$user.PHP_EOL;
    $catalog = [];
    foreach (['/2.0/language', '/3.0/currencies', '/2.0/payment_type', '/2.0/unit', '/3.0/banking/accounts', '/2.0/accounts', '/3.0/taxes'] as $path) {
        $rows = $get($path);
        $catalog[$path] = $rows;
        echo $path.' count='.count($rows).PHP_EOL;
        foreach (array_slice($rows, 0, in_array($path, ['/2.0/accounts', '/3.0/taxes'], true) ? 30 : 8) as $row) {
            if (is_array($row)) {
                echo json_encode(array_intersect_key($row, array_flip(['id', 'account_no', 'name', 'code', 'iso_code', 'value', 'percentage', 'type'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            }
        }
    }
    if (! $apply) {
        $activeTaxes = $get('/3.0/taxes', ['types' => 'sales_tax', 'scope' => 'active']);
        echo 'Active sales taxes: '.json_encode($activeTaxes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $contacts = [];
    for ($offset = 0; $offset < 5000; $offset += 500) {
        $page = $get('/2.0/contact', ['limit' => 500, 'offset' => $offset]);
        $contacts = array_merge($contacts, $page);
        if (count($page) < 500) {
            break;
        }
        if ($offset === 4500) {
            throw new RuntimeException('Contact scan limit exceeded', 409);
        }
    }
    $fixtureIds = [];
    foreach (['Member', 'Parent', 'Supplier'] as $kind) {
        $name = 'Clubora Sandbox '.$kind.' v1';
        $marker = 'clubora-sandbox-fixture:v1:'.strtolower($kind);
        $found = array_values(array_filter($contacts, fn ($row) => ($row['name_1'] ?? '') === $name && ($row['remarks'] ?? '') === $marker));
        if (count($found) > 1) {
            throw new RuntimeException('Duplicate fixture', 409);
        }
        if ($found) {
            $fixtureIds[$kind] = (int) $found[0]['id'];
            echo $kind.' existing id='.$found[0]['id'].PHP_EOL;

            continue;
        }
        // Never retry POST: inspect existing fixtures after an ambiguous response.
        $response = $request()->post('https://api.bexio.com/2.0/contact', [
            'contact_type_id' => $kind === 'Supplier' ? 1 : 2, 'name_1' => $name,
            'name_2' => $kind === 'Supplier' ? null : 'Test', 'user_id' => (int) $user, 'owner_id' => (int) $user,
            'street_name' => 'Teststrasse', 'house_number' => '1', 'postcode' => '4450', 'city' => 'Sissach',
            'mail' => strtolower($kind).'@example.invalid', 'remarks' => $marker,
        ]);
        if ($response->status() !== 201 || ! is_numeric($response->json('id'))) {
            throw new RuntimeException('Contact creation failed: '.$kind, $response->status());
        }
        $id = (int) $response->json('id');
        $check = $get('/2.0/contact/'.$id);
        if (($check['name_1'] ?? '') !== $name || ($check['remarks'] ?? '') !== $marker) {
            throw new RuntimeException('Fixture verification failed', 502);
        }
        echo $kind.' created and verified id='.$id.PHP_EOL;
        $fixtureIds[$kind] = $id;
    }
    if (in_array('--create-invoice', $argv, true)) {
        $pick = function (string $path, string $field, string $value) use ($catalog): int {
            $matches = array_values(array_filter($catalog[$path], fn ($row) => (string) ($row[$field] ?? '') === $value));
            if (count($matches) !== 1) {
                throw new RuntimeException('Ambiguous sandbox lookup: '.$path, 409);
            }

            return (int) $matches[0]['id'];
        };
        $reference = 'clubora-sandbox-fixture:v1:invoice-member';
        $invoices = $get('/2.0/kb_invoice', ['limit' => 500]);
        if (count($invoices) >= 500) {
            throw new RuntimeException('Invoice scan limit exceeded', 409);
        }
        $found = array_values(array_filter($invoices, fn ($row) => ($row['api_reference'] ?? '') === $reference));
        if (count($found) > 1) {
            throw new RuntimeException('Duplicate invoice fixture', 409);
        }
        if ($found) {
            $invoiceId = (int) $found[0]['id'];
            echo 'Invoice existing id='.$invoiceId.PHP_EOL;
        } else {
            $payload = [
                'title' => 'Clubora Sandbox Testrechnung v1 - nicht versenden',
                'contact_id' => $fixtureIds['Member'], 'user_id' => (int) $user,
                'language_id' => $pick('/2.0/language', 'name', 'Deutsch'),
                'currency_id' => $pick('/3.0/currencies', 'name', 'CHF'),
                'bank_account_id' => $pick('/3.0/banking/accounts', 'name', 'Example Bank'),
                'payment_type_id' => $pick('/2.0/payment_type', 'name', 'Rechnung'),
                'mwst_type' => 2, 'mwst_is_net' => true, 'show_position_taxes' => false,
                'is_valid_from' => now('Europe/Zurich')->toDateString(),
                'is_valid_to' => now('Europe/Zurich')->addDays(30)->toDateString(),
                'api_reference' => $reference,
                'positions' => [['type' => 'KbPositionCustom', 'amount' => '1.000000',
                    'unit_id' => $pick('/2.0/unit', 'name', 'Stk'),
                    'account_id' => $pick('/2.0/accounts', 'account_no', '3200'),
                    'tax_id' => $pick('/3.0/taxes', 'code', 'UN81'),
                    'text' => 'Synthetischer ClubUnio Testbeitrag - keine echte Forderung',
                    'unit_price' => '25.000000', 'discount_in_percent' => '0.000000']],
            ];
            $response = $request()->post('https://api.bexio.com/2.0/kb_invoice', $payload);
            if ($response->status() !== 201 || ! is_numeric($response->json('id'))) {
                if ($response->status() === 422) {
                    echo 'Synthetic invoice validation: '.substr(json_encode($response->json(), JSON_UNESCAPED_UNICODE), 0, 1500).PHP_EOL;
                }
                throw new RuntimeException('Invoice creation failed', $response->status());
            }
            $invoiceId = (int) $response->json('id');
            echo 'Invoice created id='.$invoiceId.PHP_EOL;
        }
        $invoice = $get('/2.0/kb_invoice/'.$invoiceId);
        if (($invoice['api_reference'] ?? '') !== $reference || (int) ($invoice['contact_id'] ?? 0) !== $fixtureIds['Member']) {
            throw new RuntimeException('Invoice verification failed', 502);
        }
        echo 'Invoice verified; status='.($invoice['kb_item_status_id'] ?? 'unknown').'; total='.($invoice['total'] ?? 'unknown').PHP_EOL;
        if ((int) ($invoice['mwst_type'] ?? -1) !== 2 || (float) ($invoice['total_taxes'] ?? -1) !== 0.0 || (float) ($invoice['total'] ?? -1) !== 25.0) {
            throw new RuntimeException('Unexpected exemption or total; inspect draft before continuing', 409);
        }
        $pdf = $get('/2.0/kb_invoice/'.$invoiceId.'/pdf');
        $bytes = base64_decode($pdf['content'] ?? '', true);
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('PDF verification failed', 502);
        }
        echo 'PDF verified; bytes='.strlen($bytes).PHP_EOL;
    }
} catch (Throwable $error) {
    // No raw HTTP bodies, tokens or personal contact data in diagnostics.
    fwrite(STDERR, 'Sandbox check stopped; code '.(int) $error->getCode().'; '.($error instanceof RuntimeException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
