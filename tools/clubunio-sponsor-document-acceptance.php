<?php

// Explicit, CLI-only live acceptance. Synthetic rows and financial evidence stay traceable.
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\DocumentSeriesRecipient;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\DocumentSeriesService;
use App\ClubUnio\Services\SponsorRunBexioClient;
use App\ClubUnio\Services\SponsorRunBillingService;
use App\ClubUnio\Services\SponsorRunService;
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (PHP_SAPI !== 'cli' || getenv('CLUBUNIO_LIVE_ACCEPTANCE') !== '1') {
    throw new LogicException('Explicit CLI live-acceptance opt-in required');
}
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora' && ! config('clubunio.outbound_enabled'), 409);
$tenant = Tenant::where('slug', 'sv-sissach')->sole();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
$file = storage_path('app/private/sponsor-documents-acceptance-20261025.json');
$state = is_file($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) : [];
$save = function () use (&$state, $file) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    @chmod($file, 0600);
};
$series = app(DocumentSeriesService::class);
$core = app(SponsorRunService::class);
$billing = app(SponsorRunBillingService::class);
$mode = $argv[1] ?? 'inspect';

try {
    if ($mode === 'inspect') {
        $catalogs = $billing->catalogs($actor, $tenant);
        $out = [];
        foreach ($catalogs as $kind => $rows) {
            if ($kind === 'countries') {
                continue;
            }
            $out[$kind] = array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'name', 'name_1', 'title', 'code', 'number', 'percentage', 'value', 'is_active', 'is_locked'])), $rows);
        }
        $parameters = [];
        foreach (SponsorRunBillingService::FIELDS as $key => $label) {
            $legacyKey = $key === 'due_days' ? 'BEXIO_INVOICE_DUE_DAYS' : 'BEXIO_'.strtoupper($key);
            $row = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', $legacyKey)->first();
            $value = $row ? Crypt::decryptString($row->encrypted_value) : '';
            $parameters[$key] = ctype_digit($value) ? (int) $value : null;
        }
        echo json_encode(['sandbox_verified' => true, 'catalogs' => $out, 'existing_numeric_parameters' => $parameters, 'module_configuration' => $billing->configuration($tenant), 'fixture' => array_diff_key($state, ['baseline' => true, 'original_billing_config' => true])], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'country-diagnostic') {
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(SponsorRunBexioClient::class);
        $client->verify($token);
        $rows = $client->catalog($token, 'countries');
        echo json_encode(['sample' => array_slice($rows, 0, 3), 'switzerland' => array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(json_encode($r)), 'schweiz') || str_contains(mb_strtolower(json_encode($r)), 'switzerland')))], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'fixture') {
        abort_if($state, 409, 'Existing acceptance journal: reuse, never recreate.');
        abort_if($db->table('clubora.members')->where('tenant_id', $tenant->id)->where('last_name', 'QA-Sponsor-Dokumente-20261025')->exists(), 409, 'Fixture exists without journal; investigate.');
        $state = $db->transaction(function () use ($db, $tenant, $actor, $core, $series) {
            $s = ['run' => '20261025-sponsor-documents-v1', 'tenant' => (int) $tenant->id, 'baseline' => []];
            foreach (['members', 'seasons', 'teams', 'team_seasons', 'team_players', 'sponsor_run_events', 'document_series'] as $table) {
                $rows = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id')->get();
                $s['baseline'][$table] = ['ids' => $rows->pluck('id')->all(), 'sha256' => hash('sha256', $rows->toJson())];
            }
            $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
            $s['season'] = $insert('seasons', ['name' => 'QA-Abnahme Dokumente/Sponsorenlauf 20261025']);
            $s['team'] = $insert('teams', ['name' => 'QA-Abnahme 20261025']);
            $s['team_season'] = $insert('team_seasons', ['season_id' => $s['season'], 'team_id' => $s['team'], 'label' => 'QA-Abnahme 20261025']);
            $s['member'] = $insert('members', ['first_name' => 'Synthetisch', 'last_name' => 'QA-Sponsor-Dokumente-20261025', 'birth_date' => '2000-01-01', 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Testort', 'email' => 'admin@svsissach.ch']);
            $s['team_player'] = $insert('team_players', ['team_season_id' => $s['team_season'], 'member_id' => $s['member']]);
            $event = $core->saveEvent($actor, $tenant, null, ['season_id' => $s['season'], 'title' => 'QA-Abnahme Sponsorenlauf 20261025', 'place' => 'Synthetischer Testort', 'event_date' => now('Europe/Zurich')->toDateString(), 'registration_deadline' => now('Europe/Zurich')->toDateString(), 'status_code' => 'open', 'team_ids' => [$s['team_season']], 'revision' => 0]);
            $s['event'] = (int) $event->id;
            $s['participant'] = (int) SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->sole()->id;
            $s['template'] = (int) $series->saveTemplate($actor, $tenant, ['name' => 'QA-Abnahme 20261025', 'title' => 'QA-Dokument {{Mitglied.Vorname}}', 'text' => 'Ausschliesslich synthetische technische Abnahme. Keine Materialuebergabe, keine rechtliche Wirkung. {{Team.Name}}', 'type_id' => DocumentType::where('tenant_id', $tenant->id)->where('code', 'MATERIALQUITTUNG')->where('active', true)->sole()->id, 'signature_required' => true, 'items' => [['description' => 'Synthetisches Testmaterial', 'quantity' => 1, 'price_cents' => 100]]])->id;

            return $s;
        });
        $save();
        echo json_encode(array_diff_key($state, ['baseline' => true]), JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'documents') {
        abort_unless(($state['run'] ?? '') === '20261025-sponsor-documents-v1', 409);
        $input = ['template_id' => $state['template'], 'teams' => [$state['team_season']], 'target' => 'players', 'date' => now('Europe/Zurich')->toDateString(), 'signature_required' => true, 'portal_visible' => false];
        if (! isset($state['series'])) {
            $preview = $series->preview($actor, $tenant, $input);
            abort_unless($preview['can_create'] && count($preview['snapshot']['recipients']) === 1 && $preview['snapshot']['recipients'][0]['member_id'] === $state['member'], 409);
            $state['series'] = (int) $series->create($actor, $tenant, $input, $preview['hash'], 'qa-20261025-document-series-v1')->id;
            $save();
        }
        $result = $series->process($actor, $tenant, $state['series']);
        $row = DocumentSeriesRecipient::where('tenant_id', $tenant->id)->where('series_id', $state['series'])->sole();
        abort_unless($row->document_id && ! $row->receipt_id && $result['done'], 409);
        $document = MemberDocument::where('tenant_id', $tenant->id)->findOrFail($row->document_id);
        $blob = app(AzureDocumentReader::class)->read($document->storage_path, $document->filename);
        abort_unless(hash_equals($row->original_sha256, hash('sha256', $blob)), 409);
        $response = $series->download($actor, $tenant, $state['series'], $row->id);
        abort_unless(str_starts_with($response->getContent(), '%PDF-') && hash_equals($row->original_sha256, hash('sha256', $response->getContent())), 409);
        $download = storage_path('app/private/qa-series-20261025.pdf');
        file_put_contents($download, $response->getContent());
        @chmod($download, 0600);
        $state['recipient'] = (int) $row->id;
        $state['document'] = (int) $document->id;
        $save();
        $url = 'https://'.config('clubunio-integrations.azure.account').'.blob.core.windows.net/'.config('clubunio-integrations.azure.container').'/'.$document->storage_path.$document->filename;
        $anonymous = (new Client)->request('GET', $url, ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 15, 'stream' => true]);
        $status = $anonymous->getStatusCode();
        $anonymous->getBody()->close();
        abort_unless(in_array($status, [401, 403, 404], true), 409, 'Blob must be private.');
        $again = $series->process($actor, $tenant, $state['series']);
        abort_unless($again['done'] && $series->recipients($actor, $tenant, $state['series'])->count() === 1, 409);
        echo json_encode(['series' => $state['series'], 'recipient' => $row->id, 'document' => $document->id, 'sha256' => $row->original_sha256, 'bytes' => strlen($response->getContent()), 'private_blob_status' => $status, 'repeat_without_duplicate' => true, 'receipt_absent_for_private_document' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'billing-config') {
        abort_unless($state, 409);
        $c = json_decode($argv[2] ?? '', true, 64, JSON_THROW_ON_ERROR);
        if (! isset($state['original_billing_config'])) {
            $state['original_billing_config'] = $billing->configuration($tenant);
            $save();
        }
        $billing->saveConfiguration($actor, $tenant, $c);
        echo "Module configuration saved; existing Bexio settings unchanged.\n";
    } elseif ($mode === 'billing') {
        abort_unless($state, 409);
        if (! isset($state['pledge'])) {
            $pledge = $core->addPledge($actor, $tenant, $state['participant'], ['request_key' => 'qa-20261025-sponsor-invoice-v1', 'sponsor_name' => 'QA-Abnahme 20261025', 'payment_mode' => 'invoice', 'amount_type' => 'per_lap', 'amount_cents' => 100, 'cap_cents' => 300, 'recipient_type' => 'person', 'first_name' => 'QA', 'last_name' => 'ClubUnio Sponsor 20261025', 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Testort', 'country' => 'CH', 'email' => 'admin@svsissach.ch']);
            $state['pledge'] = (int) $pledge->id;
            $save();
        }
        $p = SponsorRunParticipant::where('tenant_id', $tenant->id)->findOrFail($state['participant']);
        if ($p->draft_laps === null) {
            $core->operations($actor, $tenant, $p->id, $p->revision, 'laps', 5);
            $p->refresh();
        }
        if ($p->final_laps === null) {
            $core->operations($actor, $tenant, $p->id, $p->revision, 'approve', 5);
        }
        $state['bill'] ??= (int) $billing->prepare($actor, $tenant, $state['pledge']);
        $save();
        $action = $argv[2] ?? 'status';
        abort_unless(in_array($action, ['status', 'create-contact', 'create-draft', 'issue', 'send', 'sync'], true), 422);
        $row = $billing->get($actor, $tenant, $state['bill']);
        if ($action !== 'status') {
            $row = $billing->execute($actor, $tenant, $row->id, $row->revision, $action);
        }
        echo json_encode(['bill' => $row->id, 'state' => $row->state, 'revision' => $row->revision, 'total_cents' => $row->total_cents, 'contact_id' => $row->contact_id, 'remote_id' => $row->remote_id, 'paid_cents' => $row->paid_cents, 'credit_cents' => $row->credit_cents], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'pdf') {
        abort_unless(isset($state['bill']), 409);
        $response = $billing->pdf($actor, $tenant, $state['bill']);
        $path = storage_path('app/private/qa-sponsor-20261025.pdf');
        file_put_contents($path, $response->getContent());
        @chmod($path, 0600);
        echo json_encode(['pdf' => true, 'bytes' => strlen($response->getContent()), 'sha256' => hash('sha256', $response->getContent())], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode === 'verify') {
        abort_unless($state, 409);
        foreach ($state['baseline'] as $table => $baseline) {
            $actual = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson();
            abort_unless(hash_equals($baseline['sha256'], hash('sha256', $actual)), 409, 'Baseline changed: '.$table);
        }
        echo "Pre-existing member/season/team/event/document rows unchanged.\n";
    } elseif ($mode === 'deactivate-reference-fixture') {
        abort_unless(($state['run'] ?? '') === '20261025-sponsor-documents-v1', 409);
        if (! ($state['reference_fixture_inactive'] ?? false)) {
            $db->transaction(function () use ($db, $tenant, $actor, $core, $state) {
                $event = $core->event($tenant, $state['event']);
                $core->saveEvent($actor, $tenant, $event->id, $event->only(['season_id', 'title', 'place']) + ['event_date' => $event->event_date->toDateString(), 'registration_deadline' => $event->registration_deadline->toDateString(), 'status_code' => 'closed', 'team_ids' => [$state['team_season']], 'revision' => $event->revision, 'reason' => 'Synthetische technische Abnahme abgeschlossen; kein echter Lauf.']);
                foreach (['team_players' => 'team_player', 'team_seasons' => 'team_season', 'teams' => 'team', 'seasons' => 'season'] as $table => $key) {
                    $id = $state[$key];
                    abort_unless(! in_array($id, $state['baseline'][$table]['ids'], true), 409);
                    $count = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('id', $id)->update(['active' => false]);
                    abort_unless($count === 1, 409);
                    $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $table, 'object_id' => $id, 'after_value' => json_encode(['operation' => 'qa_acceptance_deactivated', 'active' => false], JSON_THROW_ON_ERROR)]);
                }
            });
            $state['reference_fixture_inactive'] = true;
            $save();
        }
        echo "QA season/team/assignment inactive, test event closed; private document and financial evidence retained.\n";
    } elseif ($mode === 'restore-config') {
        abort_unless(isset($state['original_billing_config']), 409);
        $billing->saveConfiguration($actor, $tenant, $state['original_billing_config']);
        echo "Previous module configuration restored; acceptance evidence retained.\n";
    } else {
        abort(422);
    }
} catch (Throwable $e) {
    $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : $e->getCode();
    fwrite(STDERR, json_encode(['failed_mode' => $mode, 'class' => $e::class, 'status' => $status, 'message' => $e instanceof ValidationException ? $e->errors() : ($e instanceof HttpExceptionInterface ? $e->getMessage() : 'Acceptance stopped; inspect stable source guards, no blind retry.')], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
