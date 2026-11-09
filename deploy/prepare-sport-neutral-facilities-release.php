<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\FacilityPlanningMode;
use App\ClubUnio\Services\PitchPlanningProjection;
use Illuminate\Support\Facades\DB;

try {
    $root = '/var/www/svs-laravel';
    $release = "$root/releases/20261109-clubunio-sport-neutral-facilities";
    $previous = "$root/releases/20261109-clubunio-my-teams-trial";
    $mode = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || realpath(dirname(__DIR__)) !== $release
        || realpath("$root/current") !== ($mode === 'live' ? $release : $previous)
        || ! in_array($mode, ['check', 'dump', 'migrate', 'live'], true)) {
        throw new LogicException('Expected pinned ClubUnio release.');
    }

    require "$release/vendor/autoload.php";
    $app = require "$release/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) {
        throw new LogicException('Expected separate ClubUnio DEV database.');
    }
    if ($mode === 'dump') {
        $host = $db->getConfig('host');
        $user = $db->getConfig('username');
        $port = (string) $db->getConfig('port');
        $password = $db->getConfig('password');
        if (! is_string($host) || ! preg_match('/^[a-z0-9-]+\.site4now\.net$/D', $host)
            || ! is_string($user) || ! preg_match('/^[a-z0-9_]+$/D', $user)
            || ! ctype_digit($port) || ! is_string($password) || preg_match('/[\r\n]/', $password)) {
            throw new LogicException('Unexpected database connection format.');
        }
        $proc = proc_open(['docker', 'run', '--rm', '--network', 'host', '--read-only', '-i', '--entrypoint', 'sh', 'postgres:18-alpine',
            '-ec', 'IFS= read -r PGPASSWORD; export PGPASSWORD PGSSLMODE=verify-full PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt; exec pg_dump "$@"',
            'sh', '-h', $host, '-p', $port, '-U', $user, '-d', $db->getDatabaseName(), '--schema=clubora',
            '--format=custom', '--compress=1', '--lock-wait-timeout=5s'],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (! is_resource($proc)) {
            throw new LogicException('Database backup could not start.');
        }
        fwrite($pipes[0], $password."\n");
        fclose($pipes[0]);
        exit(proc_close($proc));
    }

    $db->transaction(function () use ($db, $mode, $release) {
        $db->statement($mode === 'migrate' ? 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' : 'SET TRANSACTION READ ONLY');
        $db->statement("SET LOCAL lock_timeout TO '5s'");
        $db->statement("SET LOCAL statement_timeout TO '30s'");
        if ($mode === 'migrate') {
            $db->statement('LOCK TABLE clubora.migrations IN SHARE ROW EXCLUSIVE MODE');
        }
        $name = '2026_11_09_000100_sport_neutral_facilities';
        $ledger = $db->table('clubora.migrations');
        $svs = Tenant::where('slug', 'sv-sissach')->sole();
        $live = $mode === 'live';
        if (! app(PitchPlanningProjection::class)->ready() || FacilityPlanningMode::neutral($svs)
            || FacilityPlanningMode::ready() !== $live
            || $db->getSchemaBuilder()->hasColumn('clubora.training_options', 'facility_kind') !== $live
            || (clone $ledger)->where('migration', $name)->count() !== (int) $live) {
            throw new LogicException('Unexpected planning runtime or migration baseline.');
        }
        $snapshot = function () use ($db, $svs) {
            $fingerprints = [];
            foreach (['training_options', 'training_slots', 'field_availabilities', 'facility_statuses', 'tenant_settings'] as $table) {
                $fingerprints[$table] = $db->selectOne("SELECT count(*) AS n,
                    md5(coalesce(string_agg((to_jsonb(r) - 'facility_kind')::text, ',' ORDER BY r.id), '')) AS digest
                    FROM clubora.$table r WHERE tenant_id = ?", [$svs->id]);
            }

            return json_encode($fingerprints, JSON_THROW_ON_ERROR);
        };
        if ($mode === 'migrate') {
            $before = $snapshot();
            (require "$release/database/clubunio/$name.php")->up();
            if ($db->table('clubora.tenants')->where('facility_planning_mode', '<>', 'legacy')->exists()
                || $db->table('clubora.training_options')->whereNotNull('facility_kind')->exists()
                || ! hash_equals($before, $snapshot())) {
                throw new LogicException('Migration changed existing tenant planning data.');
            }
            (clone $ledger)->insert(['migration' => $name, 'batch' => (int) (clone $ledger)->max('batch') + 1]);
            echo "One additive migration applied; five SV Sissach data sets unchanged.\n";
        } elseif ($live) {
            if ($svs->facility_planning_mode !== 'legacy') {
                throw new LogicException('SV Sissach must remain in the legacy mode.');
            }
            $fields = app(PitchPlanningProjection::class)->fields($svs);
            if ($fields === [] || array_filter($fields, fn ($field) => array_key_exists('facility_kind', $field))) {
                throw new LogicException('Unexpected SV Sissach field projection.');
            }
            printf("SV Sissach legacy projection verified (%d active fields); neutral clubs: %d.\n",
                count($fields), $db->table('clubora.tenants')->where('facility_planning_mode', 'neutral')->count());
        } else {
            echo "Inactive ClubUnio DEV planning candidate and pre-migration schema verified.\n";
        }
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Facilities release gate failed: '.get_class($error).($error instanceof LogicException ? ': '.$error->getMessage() : '').PHP_EOL);
    exit(1);
}
