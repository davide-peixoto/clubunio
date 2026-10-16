<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CLI-only, read-only page profiling. Never output HTML, SQL bindings or identities.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = $argv[1] ?? '/members';
abort_unless(in_array($path, ['/', '/members', '/invoices', '/expenses', '/configuration', '/planning'], true), 422);
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
config(['session.driver' => 'array']);
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $actor = Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)->where('tenant_accounts.role', 'admin'))->orderBy('id')->firstOrFail();
    Auth::guard('clubunio')->setUser($actor);
    $app['session']->driver()->put('clubunio_context.'.$actor->id, ['tenant' => $tenant->id, 'revision' => (string) Str::uuid()]);
    $queries = [];
    $db->listen(function ($query) use (&$queries) {
        $hash = substr(hash('sha256', $query->sql), 0, 12);
        if (! isset($queries[$hash])) {
            preg_match('/(?:from|join)\s+"?(?:clubora"?\.)?"?([a-z_]+)/i', $query->sql, $table);
            $queries[$hash] = ['fingerprint' => $hash, 'table' => $table[1] ?? 'other', 'count' => 0, 'ms' => 0];
        }
        $queries[$hash]['count']++;
        $queries[$hash]['ms'] += $query->time;
    });
    $request = Request::create('https://dev.clubunio.ch'.$path, 'GET');
    $start = microtime(true);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $mode = $argv[2] ?? 'page';
    if (in_array($mode, ['search', 'filter-search'], true)) {
        abort_unless($response->getStatusCode() === 200, 409);
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $snapshot = null;
        foreach ($dom->getElementsByTagName('*') as $node) {
            if (! $node->hasAttribute('wire:snapshot')) {
                continue;
            }
            $candidate = json_decode($node->getAttribute('wire:snapshot'), true, 512, JSON_THROW_ON_ERROR);
            if (array_key_exists('tableSearch', $candidate['data'])) {
                $snapshot = $candidate;
                break;
            }
        }
        abort_unless($snapshot, 409);
        $request->setMethod('POST');
        if ($mode === 'filter-search') {
            abort_unless($path === '/members', 422);
            $status = $db->table('clubora.members')->where('tenant_id', $tenant->id)->whereNotNull('status_id')->value('status_id');
            abort_unless($status, 409);
            [$snapshot] = app('livewire')->update($snapshot, ['tableFilters.status_id.value' => (string) $status], []);
        }
        $queries = [];
        $start = microtime(true);
        [$updated, $effects] = app('livewire')->update($snapshot, ['tableSearch' => 'a'], []);
        abort_unless(isset($effects['html']), 409);
    }
    $elapsed = round((microtime(true) - $start) * 1000);
    usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
    echo json_encode(['path' => $path, 'mode' => $mode, 'status' => $response->getStatusCode(), 'elapsed_ms' => $elapsed,
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'query_count' => array_sum(array_column($queries, 'count')),
        'query_ms' => round(array_sum(array_column($queries, 'ms'))), 'top_queries' => array_slice($queries, 0, 12)], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->rollBack();
}
