<?php

if (! in_array('--synthetic-rollback', $argv, true)) { throw new LogicException('Explicit synthetic transaction required'); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected document test database'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$stage = 'fixture'; $temp = null; $failed = false;
$call = function ($method, $path, $body = [], $token = null, $files = []) use ($kernel, &$stage) {
    $stage = $method.' '.$path;
    $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'dev.clubunio.ch', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CLUBUNIO_MOBILE_FEATURES' => 'member-documents-v1'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    if (! $files) { $server['CONTENT_TYPE'] = 'application/json'; }
    $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, $files ? $body : [], [], $files, $server, $files ? null : json_encode($body));
    $response = $kernel->handle($request); $stage .= ' [HTTP '.$response->getStatusCode().']';
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response];
};
$expect = function ($actual, $expected) { if ($actual !== $expected) { throw new LogicException('Unexpected document functional result'); } };
$db->beginTransaction();
$db->statement("SET LOCAL application_name TO 'clubunio-mobile-documents-release-check'");
try {
    $enabled = (bool) config('clubunio-mobile.native_documents_enabled', false);
    $tenant = App\ClubUnio\Models\Tenant::create(['slug' => 'qa-doc-release-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic document release']);
    $password = bin2hex(random_bytes(16)); $actor = new App\ClubUnio\Models\Account;
    $actor->forceFill(['username' => 'qa-doc-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic signer',
        'password' => Illuminate\Support\Facades\Hash::make($password), 'active' => true, 'api_only' => false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
    $member = $db->table('clubora.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Signer', 'birth_date' => '1980-01-01']);
    $db->table('clubora.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic-release']);
    [$status, $login] = $call('POST', 'session', ['username' => $actor->username, 'password' => $password, 'device_name' => 'Synthetic document release']);
    $expect($status, 200); $token = $login['data']['token']; $base = 'clubs/'.$tenant->id.'/member-documents';
    [$status, $profile] = $call('GET', 'clubs/'.$tenant->id.'/members/'.$member, [], $token); $expect($status, 200);
    $expect($profile['data']['Member']['CanReadDocuments'], $enabled);
    [$status] = $call('GET', $base, [], $token); $expect($status, $enabled ? 200 : 503);
    if ($enabled) {
        $type = $db->table('clubora.document_types')->insertGetId(['tenant_id' => $tenant->id, 'code' => 'SYNTHETIC_RELEASE', 'name' => 'Synthetic receipt']);
        $pdf = new FPDF; $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 14); $pdf->Cell(0, 10, 'Synthetic DEV release check - no real person or document');
        $original = $pdf->Output('S'); $hash = hash('sha256', $original);
        $temp = tempnam(sys_get_temp_dir(), 'clubunio-doc-qa-'); if ($temp === false) { throw new RuntimeException('Synthetic file unavailable'); }
        chmod($temp, 0600); if (file_put_contents($temp, $original) !== strlen($original)) { throw new RuntimeException('Synthetic file incomplete'); }
        $input = ['MitgliederID' => $member, 'Titel' => 'Synthetic release receipt', 'DokumentTypID' => $type,
            'UnterschriftNotwendig' => '1', 'RequestKey' => 'synthetic-release-'.bin2hex(random_bytes(12))];
        $file = fn () => ['file' => new Illuminate\Http\UploadedFile($temp, 'synthetic.pdf', 'application/pdf', null, true)];
        [$status, $created] = $call('POST', $base, $input, $token, $file()); $expect($status, 200);
        $id = $created['data']['Dokument']['QuittungID']; $expect($created['data']['Dokument']['OriginalSha256'], $hash);
        [$status, $replay] = $call('POST', $base, $input, $token, $file()); $expect($status, 200); $expect($replay['data']['Dokument']['QuittungID'], $id);
        $signature = ['Revision' => 1, 'OriginalSha256' => $hash, 'Accepted' => true, 'Strokes' => [[[.1, .3], [.2, .2], [.3, .8], [.9, .4]]]];
        [$status, $signed] = $call('POST', $base.'/'.$id.'/sign', $signature, $token); $expect($status, 200);
        $expect($signed['data']['Dokument']['Status'], 'UNTERSCHRIEBEN'); $expect($signed['data']['Dokument']['Revision'], 2);
        [$status, $replayed] = $call('POST', $base.'/'.$id.'/sign', $signature, $token); $expect($status, 200); $expect($replayed['data']['Dokument']['Revision'], 2);
        [$status, , $response] = $call('GET', $base.'/'.$id.'/pdf?original=1', [], $token); $expect($status, 200);
        $expect(hash('sha256', $response->getContent()), $hash); $expect($response->headers->get('X-Content-SHA256'), $hash);
        [$status, , $response] = $call('GET', $base.'/'.$id.'/pdf', [], $token); $expect($status, 200);
        $expect(hash('sha256', $response->getContent()), $signed['data']['Dokument']['SigniertSha256']);
        $expect($response->headers->get('X-Content-SHA256'), $signed['data']['Dokument']['SigniertSha256']);
        [$status, $list] = $call('GET', $base, [], $token); $expect($status, 200); $expect($list['data']['totalRecordCount'], 1);
    }
    echo 'Personal document '.($enabled ? 'scan/upload/original/drawn-signature/replay/download' : 'closed foundation')." HTTP-kernel checks passed; synthetic transaction rolled back.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Document functional gate failed at '.$stage.': '.get_class($error)." (record contents suppressed).\n"); $failed = true;
} finally { if ($temp && is_file($temp)) { unlink($temp); } $db->rollBack(); }
if ($failed) { exit(1); }
