<?php

declare(strict_types=1);

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{MessageTemplateService, SeasonReferenceAudit, StageMigrationTarget, StatusStyleService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Artisan, Crypt, DB, Http};

if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$checkpoints = [
    '2026_10_11_000001_create_foundation' => 'clubunio:import-foundation',
    '2026_10_11_000002_add_club_objects' => 'clubunio:import-objects',
    '2026_10_11_000003_expand_members' => 'clubunio:import-member-details',
    '2026_10_11_000004_team_assignments' => 'clubunio:import-team-assignments',
    '2026_10_11_000005_member_dossier' => 'clubunio:import-member-dossier',
    '2026_10_11_000008_account_member_access' => 'clubunio:import-account-links',
    '2026_10_11_000010_registration_details' => 'clubunio:import-registration-details',
    '2026_10_11_000015_contributions_training' => 'clubunio:import-contributions-training',
    '2026_10_12_000019_status_styles' => 'clubunio:import-facilities',
    '2026_10_12_000022_team_planning' => 'clubunio:planning-import',
    '2026_10_12_000032_football_clubs' => 'clubunio:import-football-clubs',
];
$phase = 'plan';

try {
    $files = glob($root.'/database/clubunio/*.php') ?: [];
    sort($files, SORT_STRING);
    $names = array_map(fn (string $file) => basename($file, '.php'), $files);
    if (array_diff(array_keys($checkpoints), $names)) throw new LogicException('Missing migration checkpoint');
    if (array_slice($argv, 1) === ['--plan'] || count($argv) === 1) {
        foreach ($names as $name) echo $name.(isset($checkpoints[$name]) ? ' -> '.$checkpoints[$name] : '').PHP_EOL;
        echo 'Plan only. No database connections opened.'.PHP_EOL;
        exit;
    }
    if (count($argv) !== 3 || ! in_array($argv[1], ['--apply', '--resume-build'], true)) {
        throw new LogicException('Use --plan, --apply <stage-db>, or --resume-build <stage-db>');
    }
    $expected = $argv[2];
    StageMigrationTarget::assertName($expected);
    $phase = 'private configuration';
    $path = $root.'/.env.stage-migration.local';
    if (! is_file($path)) throw new LogicException('Missing private Stage configuration');
    $settings = Dotenv\Dotenv::parse(file_get_contents($path));
    foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLROOTCERT', 'APP_KEY'] as $key) {
        if (empty($settings[$key])) throw new LogicException('Incomplete private Stage configuration');
    }
    $encodedKey = $settings['APP_KEY'];
    if ($settings['PGDATABASE'] !== $expected || ! is_readable($settings['PGSSLROOTCERT'])
        || ! str_starts_with($encodedKey, 'base64:') || strlen((string) base64_decode(substr($encodedKey, 7), true)) !== 32) {
        throw new LogicException('Invalid Stage identity, TLS CA or encryption key');
    }
    if (isset($settings['PGHOSTADDR']) && $settings['PGHOSTADDR'] !== '127.0.0.1') {
        throw new LogicException('The optional tunnel address must be loopback');
    }
    putenv(isset($settings['PGHOSTADDR']) ? 'PGHOSTADDR=127.0.0.1' : 'PGHOSTADDR');
    $app = require $root.'/bootstrap/app.php';
    if ($app->configurationIsCached()) throw new LogicException('Use a CLI checkout without cached configuration');
    $app->loadEnvironmentFrom('.env.stage-migration.local');
    $app->make(Kernel::class)->bootstrap();
    if (config('clubunio.outbound_enabled')) throw new LogicException('Outbound actions must be disabled');
    config([
        'app.key' => $encodedKey, 'app.previous_keys' => [], 'logging.default' => 'null',
        'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
        'database.default' => 'clubunio', 'database.migrations.table' => 'clubora.migrations',
        'database.connections' => ['clubunio' => [
            'driver' => 'pgsql', 'host' => $settings['PGHOST'], 'port' => $settings['PGPORT'],
            'database' => $expected, 'username' => $settings['PGUSER'], 'password' => $settings['PGPASSWORD'],
            'sslmode' => 'verify-full', 'sslrootcert' => $settings['PGSSLROOTCERT'],
            'charset' => 'utf8', 'search_path' => 'clubora', 'prefix' => '',
            'options' => defined('PDO::PGSQL_ATTR_DISABLE_PREPARES') ? [PDO::PGSQL_ATTR_DISABLE_PREPARES => true] : [],
        ]],
    ]);
    Http::preventStrayRequests();
    DB::purge('clubunio');
    $db = DB::connection('clubunio');
    StageMigrationTarget::authorize($db, $expected);
    $snapshot = static function (string ...$arguments) use ($root): void {
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_pgsql', $root.'/tools/clubunio-import.php', ...$arguments], [STDIN, STDOUT, STDERR], $pipes, $root);
        if (! is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Snapshot step failed');
    };
    if ($argv[1] === '--apply') {
        $phase = 'copy current PROD snapshot';
        $snapshot('copy-prod', '--new-empty-target', $expected);
    }
    $phase = 'verify snapshot';
    $snapshot('verify', '--stage', $expected);
    $result = $db->transaction(function () use ($db, $names, $checkpoints, &$phase): array {
        $db->select('SELECT pg_advisory_xact_lock(2026110901)');
        $run = $db->table('migration_audit.run')->sole();
        if ($run->source_database !== 'db_acdf8d_mysvs' || $run->status !== 'verified_snapshot'
            || $db->selectOne("SELECT to_regnamespace('clubora') AS existing")->existing !== null) {
            throw new LogicException('Verified PROD snapshot and absent application schema required');
        }
        $db->statement('CREATE SCHEMA clubora');
        $db->statement('REVOKE ALL ON SCHEMA clubora FROM PUBLIC');
        foreach ($names as $name) {
            $phase = $name;
            if (Artisan::call('migrate', ['--database' => 'clubunio', '--path' => 'database/clubunio/'.$name.'.php', '--force' => true]) !== 0) {
                throw new RuntimeException('Migration failed');
            }
            if (isset($checkpoints[$name])) {
                $phase = $checkpoints[$name];
                echo 'Running '.$phase.PHP_EOL;
                if (Artisan::call($phase) !== 0) throw new RuntimeException('Import failed');
            }
        }
        $phase = 'Stage safety and acceptance';
        $tenant = Tenant::where('slug', 'sv-sissach')->sole();
        app(MessageTemplateService::class)->seed($tenant);
        app(StatusStyleService::class)->seed($tenant);
        $db->table('clubora.accounts')->update(['active' => false, 'password' => '']);
        $db->table('clubora.tenant_settings')->where('tenant_id', $tenant->id)->where('is_secret', true)
            ->update(['enabled' => false, 'encrypted_value' => Crypt::encryptString('')]);
        if ($db->table('clubora.tenants')->count() !== 1 || $db->table('clubora.migrations')->count() !== count($names)
            || $db->table('clubora.imports')->count() < count($checkpoints)
            || ! app(\App\ClubUnio\Mobile\MobileSeasonScope::class)->current($tenant)) {
            throw new LogicException('Tenant, migration ledger, import checkpoints or season mapping invalid');
        }
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $audit = app(SeasonReferenceAudit::class)->inspect($db, (int) $tenant->id);
        if (array_sum(array_column($audit['references'], 'invalid_or_cross_tenant')) !== 0
            || array_sum(array_column($audit['snapshot_mappings'], 'unexplained_reference_differences')) !== 0) {
            throw new LogicException('Season reference audit failed');
        }
        return [
            'tenant' => $tenant->slug,
            'migration_count' => count($names),
            'import_count' => count($checkpoints),
            'season' => $audit['configured_lookup'],
            'source_expense_season_conflicts_for_review' => $audit['expense_team_season_disagreement'],
        ];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'Stage SV Sissach built. Imported logins and provider secrets are disabled. No web deployment performed.'.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Stage migration stopped at '.$phase.' ('.get_class($error).', '.$error->getCode().'). No source values logged.'.PHP_EOL);
    if ($phase === 'private configuration') {
        fwrite(STDERR, 'Failure location: '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    }
    if ($error instanceof \Illuminate\Database\QueryException
        && preg_match('/null value in column "([a-z_]+)" of relation "([a-z_]+)"/', $error->getMessage(), $match)) {
        fwrite(STDERR, 'Missing required field: '.$match[2].'.'.$match[1].PHP_EOL);
    }
    exit(1);
}
