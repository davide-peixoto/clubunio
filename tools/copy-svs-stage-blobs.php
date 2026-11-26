<?php

declare(strict_types=1);

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Sas\{AccountSasBuilder, SasProtocol};
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';

$source = 'svs-prod-private';
$target = 'svs-stage-private';
$phase = 'configuration';
try {
    $mode = $argv[1] ?? '--inspect';
    if (count($argv) > 2 || !in_array($mode, ['--inspect', '--copy', '--stage-read-config'], true)) {
        throw new LogicException('Unexpected arguments');
    }
    $config = getenv('CLUBUNIO_AZURE_CONFIG') ?: dirname(__DIR__).'/.env.clubora-integrations.local';
    $env = Dotenv\Dotenv::parse(file_get_contents($config));
    $account = $env['AZURE_ACCOUNT'] ?? '';
    if (!preg_match('/^[a-z0-9]{3,24}$/D', $account) || empty($env['AZURE_KEY'])) throw new LogicException('Missing Azure credentials');
    $credential = new StorageSharedKeyCredential($account, $env['AZURE_KEY']);
    $host = 'https://'.$account.'.blob.core.windows.net/';
    $sas = static fn (string $container, string $permissions, string $ttl) => (string) BlobSasBuilder::new()
        ->setContainerName($container)->setPermissions($permissions)->setProtocol(SasProtocol::HTTPS)
        ->setExpiresOn(new DateTimeImmutable($ttl))->build($credential);
    if ($mode === '--stage-read-config') {
        if (stream_isatty(STDOUT)) throw new LogicException('Private pipe required');
        echo "AZURE_READ_ENABLED=1\nAZURE_ACCOUNT=$account\nAZURE_STAGE_READ_SAS=".$sas($target, 'r', '+30 days')."\n";
        exit;
    }
    $http = new Client(['connect_timeout'=>5,'timeout'=>25,'allow_redirects'=>false,'http_errors'=>false,
        'headers'=>['x-ms-version'=>'2023-11-03']]);
    $accountRead = (string) AccountSasBuilder::new()->setServices('b')->setResourceTypes('c')->setPermissions('r')
        ->setProtocol(SasProtocol::HTTPS)->setExpiresOn(new DateTimeImmutable('+4 hours'))->build($credential);
    $private = static function (string $container, bool $allowMissing = false) use ($http, $host, $accountRead): bool {
        $r = $http->head($host.$container.'?restype=container&'.$accountRead);
        if ($allowMissing && $r->getStatusCode() === 404) return false;
        if ($r->getStatusCode() !== 200 || $r->hasHeader('x-ms-blob-public-access')) throw new RuntimeException('Container not verified private', $r->getStatusCode());
        return true;
    };
    $list = static function (string $container, string $token) use ($http, $host): array {
        $rows = []; $marker = '';
        do {
            $r = $http->get($host.$container.'?restype=container&comp=list&include=copy&marker='.rawurlencode($marker).'&'.$token);
            if ($r->getStatusCode() !== 200) throw new RuntimeException('Listing failed', $r->getStatusCode());
            $xml = simplexml_load_string((string) $r->getBody(), SimpleXMLElement::class, LIBXML_NONET);
            if (!$xml) throw new RuntimeException('Invalid listing');
            foreach ($xml->Blobs->Blob as $blob) {
                $p = $blob->Properties;
                $rows[(string) $blob->Name] = ['size'=>(int) $p->{'Content-Length'}, 'etag'=>(string) $p->Etag,
                    'md5'=>(string) $p->{'Content-MD5'}, 'copy'=>(string) $p->{'CopyStatus'}];
            }
            $marker = (string) $xml->NextMarker;
        } while ($marker !== '');
        return $rows;
    };
    $phase = 'inventory';
    $private($source);
    $exists = $private($target, true);
    $readSource = $sas($source, 'rl', '+4 hours');
    $accessTarget = $sas($target, 'rcwl', '+4 hours');
    $before = $list($source, $readSource);
    $existing = $exists ? $list($target, $accessTarget) : [];
    echo json_encode(['source_blobs'=>count($before), 'source_bytes'=>array_sum(array_column($before,'size')),
        'stage_exists'=>$exists,'stage_blobs'=>count($existing)], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($mode === '--inspect') exit;
    if (!$before) throw new LogicException('Empty source');
    if (!$exists) {
        $phase = 'create private Stage container';
        (new BlobServiceClient(new Uri($host), $credential))->getContainerClient($target)->createIfNotExists();
        $private($target);
    }
    if (array_diff_key($existing, $before)) throw new LogicException('Destination contains unrelated blobs');
    $phase = 'copy';
    $copied = 0;
    foreach ($before as $name=>$blob) {
        if (isset($existing[$name])) {
            $old = $existing[$name];
            if ($old['size'] !== $blob['size'] || $old['copy'] !== 'success'
                || ($blob['md5'] !== '' && $old['md5'] !== $blob['md5'])) throw new LogicException('Existing Stage object differs');
            continue;
        }
        $path = implode('/', array_map('rawurlencode', explode('/', (string) $name)));
        $destination = $host.$target.'/'.$path.'?'.$accessTarget;
        $r = $http->put($destination, ['headers'=>[
            'x-ms-copy-source'=>$host.$source.'/'.$path.'?'.$readSource,
            'x-ms-source-if-match'=>'"'.trim($blob['etag'], '"').'"',
            'If-None-Match'=>'*',
        ]]);
        if ($r->getStatusCode() !== 202) throw new RuntimeException('Copy rejected', $r->getStatusCode());
        $state = $r->getHeaderLine('x-ms-copy-status');
        for ($i=0; $state === 'pending' && $i<30; $i++) {
            sleep(1);
            $state = $http->head($destination)->getHeaderLine('x-ms-copy-status');
        }
        if ($state !== 'success') throw new RuntimeException('Copy incomplete');
        if (++$copied % 100 === 0) echo "Copied $copied blobs.\n";
    }
    $phase = 'verification';
    $after = $list($target, $accessTarget);
    if ($list($source, $readSource) !== $before || array_diff_key($after, $before) || array_diff_key($before, $after)) {
        throw new LogicException('Inventory changed');
    }
    foreach ($after as $name=>$blob) {
        if ($blob['size'] !== $before[$name]['size'] || $blob['copy'] !== 'success'
            || ($before[$name]['md5'] !== '' && $blob['md5'] !== $before[$name]['md5'])) throw new LogicException('Destination verification failed');
    }
    $private($target);
    echo json_encode(['verified_blobs'=>count($after),'verified_bytes'=>array_sum(array_column($after,'size')),
        'newly_copied'=>$copied], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Azure Stage operation stopped at '.$phase.' ('.get_class($e).', '.$e->getCode().'). No blob names or credentials logged.'.PHP_EOL);
    exit(1);
}
