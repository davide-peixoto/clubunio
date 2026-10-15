<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('ClubUnio target required');
}
$tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->sole();
$statuses = $db->table('clubora.registration_statuses')->where('tenant_id', $tenant->id)->where('active', true)->pluck('workflow_key')->sort()->values()->all();
$restoring = $db->table('clubora.member_statuses')->where('tenant_id', $tenant->id)->where('active', true)->where('restores_membership', true)->count();
if ($statuses !== ['accepted', 'pool', 'rejected', 'review', 'submitted', 'trial'] || $restoring !== 1) {
    throw new LogicException('Workflow/status configuration requires review');
}
echo json_encode(['workflow_keys' => $statuses, 'restoring_statuses' => $restoring,
    'registrations' => $db->table('clubora.registrations')->where('tenant_id', $tenant->id)->count(),
    'new_document_metadata_available' => Schema::connection('clubunio')->hasColumn('clubora.registration_documents', 'sha256'),
    'private_container' => config('clubunio-integrations.azure.container') === 'svs-prod-private',
    'outbound_enabled' => (bool) config('clubunio.outbound_enabled')], JSON_THROW_ON_ERROR).PHP_EOL;
