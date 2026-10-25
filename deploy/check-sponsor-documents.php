<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// Read-only release check: no fixture creation, messages or provider calls.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new RuntimeException('Wrong database');
}
foreach (['document_series_templates', 'document_series', 'document_series_recipients', 'sponsor_run_events', 'sponsor_run_participants', 'sponsor_run_pledges', 'sponsor_run_links', 'sponsor_run_public_outbox', 'sponsor_run_invoices', 'sponsor_run_billing_audit'] as $table) {
    if (! Schema::connection('clubunio')->hasTable('clubora.'.$table)) {
        throw new RuntimeException('Missing module table: '.$table);
    }
}
foreach (['filament.clubunio.pages.document-series', 'filament.clubunio.pages.sponsor-runs', 'filament.clubunio.pages.sponsor-run-billing', 'clubunio.document-series.original', 'clubunio.document-series.signed', 'clubunio.sponsor.billing.pdf', 'clubunio.portal.sponsor-runs', 'clubunio.sponsor.public.confirm'] as $name) {
    if (! Route::has($name)) {
        throw new RuntimeException('Missing module route: '.$name);
    }
}
echo "Sponsor/document schema and web routes OK; no provider writes.\n";
