<?php

// Explicit DEV-only, additive migrations84-87. No import, refresh, restore or legacy DB access.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || ! str_starts_with(realpath(base_path()), '/var/www/svs-laravel/releases/')
        || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
        || config('app.url') !== 'https://dev.clubunio.ch') {
        throw new LogicException('Reviewed Linux ClubUnio DEV release required');
    }
    if (config('clubunio-mobile.native_planning_enabled') && (in_array('--backup', $argv, true) || in_array('--apply', $argv, true))) { throw new LogicException('Planning must be closed for schema changes'); }
    $directory = '/var/www/svs-laravel/shared/backups';
    $file = $directory.'/mobile-planning-before-84-87-20261103.cuenc';
    $tables = ['team_absences', 'team_events', 'team_guests', 'team_participations'];
    $files = ['2026_11_03_000084_planning_attendance_notes', '2026_11_03_000085_planning_absence_windows',
        '2026_11_03_000086_mobile_planning_commands', '2026_11_03_000087_planning_event_removal'];
    $schema = $db->getSchemaBuilder();
    $columns = ['team_participations' => ['attendance_comment_encrypted', 'attendance_updated_at'],
        'team_guests' => ['attendance_comment_encrypted', 'attendance_updated_at'], 'team_absences' => ['starts_at', 'ends_at'], 'team_events' => ['deleted_at']];
    $expectFresh = function () use ($db, $schema, $columns, $files) {
        foreach ($columns as $table => $names) {
            foreach ($names as $name) { if ($schema->hasColumn('clubora.'.$table, $name)) { throw new LogicException('Planning schema already changed; review required'); } }
        }
        if ($schema->hasTable('clubora.mobile_planning_commands') || $db->table('clubora.migrations')->whereIn('migration', $files)->exists()) {
            throw new LogicException('Planning migration state already changed');
        }
    };
    if (in_array('--backup', $argv, true)) {
        $expectFresh();
        if (is_link($directory) || is_link($file) || file_exists($file)) { throw new LogicException('Backup destination already exists or is a link'); }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) { throw new LogicException('Private backup directory unavailable'); }
        if (realpath($directory) !== $directory) { throw new LogicException('Unexpected backup directory'); }
        umask(0077);
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $db->statement("SET LOCAL statement_timeout TO '30s'");
            $payload = ['database' => $db->getDatabaseName(), 'created_at' => gmdate('c'), 'tables' => [],
                'ledger' => $db->table('clubora.migrations')->orderBy('id')->get()->all()];
            foreach ($tables as $table) {
                $rows = $db->table('clubora.'.$table)->orderBy('id')->get()->all();
                $payload['tables'][$table] = ['columns' => $schema->getColumns('clubora.'.$table), 'rows' => $rows,
                    'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
            }
            $sealed = Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            $handle = fopen($file, 'x');
            if (! $handle) { throw new LogicException('Cannot create exclusive backup'); }
            try { if (fwrite($handle, $sealed) !== strlen($sealed)) { throw new LogicException('Incomplete backup write'); } }
            finally { fclose($handle); }
            chmod($file, 0600);
        } finally { $db->rollBack(); }
    }
    if (! is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0) { throw new LogicException('Verified private backup required'); }
    $payload = json_decode(Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
    if ($payload['database'] !== $db->getDatabaseName() || array_keys($payload['tables']) !== $tables) { throw new LogicException('Backup scope mismatch'); }
    foreach ($tables as $table) {
        if (! hash_equals($payload['tables'][$table]['sha256'], hash('sha256', json_encode($payload['tables'][$table]['rows'], JSON_THROW_ON_ERROR)))) { throw new LogicException('Backup row digest mismatch'); }
    }
    if (in_array('--apply', $argv, true)) {
        if (realpath('/var/www/svs-laravel/current') !== realpath(base_path()) || basename(base_path()) !== '20261103-clubunio-mobile-foundation') {
            throw new LogicException('Active reviewed foundation release required');
        }
        if (time() - strtotime($payload['created_at']) > 3600) { throw new LogicException('Fresh backup required'); }
        $db->transaction(function () use ($db, $schema, $expectFresh, $tables, $files, $columns, $payload) {
            $db->statement("SET LOCAL lock_timeout TO '5s'");
            $db->statement("SET LOCAL statement_timeout TO '20s'");
            if (! $db->selectOne("SELECT pg_try_advisory_xact_lock(hashtext('clubunio-mobile-84-87')) AS locked")->locked) { throw new LogicException('Concurrent planning migration'); }
            $expectFresh();
            $db->statement('LOCK TABLE '.implode(', ', array_map(fn ($table) => 'clubora.'.$table, $tables)).' IN ACCESS EXCLUSIVE MODE NOWAIT');
            if ($db->table('clubora.migrations')->orderBy('id')->get()->toJson() !== json_encode($payload['ledger'], JSON_THROW_ON_ERROR)) { throw new LogicException('Migration ledger changed since backup'); }
            $before = [];
            foreach ($tables as $table) { $before[$table] = $db->table('clubora.'.$table)->orderBy('id')->get()->all(); }
            $batch = (int) $db->table('clubora.migrations')->max('batch') + 1;
            foreach ($files as $file) {
                (require database_path('clubunio/'.$file.'.php'))->up();
                $db->table('clubora.migrations')->insert(['migration' => $file, 'batch' => $batch]);
            }
            foreach ($tables as $table) {
                $oldColumns = array_keys((array) ($before[$table][0] ?? []));
                if ($oldColumns) {
                    $after = $db->table('clubora.'.$table)->orderBy('id')->get($oldColumns)->all();
                    if (json_encode($after, JSON_THROW_ON_ERROR) !== json_encode($before[$table], JSON_THROW_ON_ERROR)) { throw new LogicException('Existing planning fields changed'); }
                } elseif ($db->table('clubora.'.$table)->exists()) { throw new LogicException('Unexpected planning data'); }
            }
            foreach ($columns as $table => $names) {
                foreach ($names as $name) {
                    if (! $schema->hasColumn('clubora.'.$table, $name) || $db->table('clubora.'.$table)->whereNotNull($name)->exists()) { throw new LogicException('Unexpected additive column state'); }
                }
            }
            if ($db->table('clubora.mobile_planning_commands')->exists()) { throw new LogicException('Unexpected planning commands'); }
        });
        echo "Applied four additive migrations atomically; existing planning fields unchanged.\n";
    } elseif (! in_array('--backup', $argv, true) && ! in_array('--verify-backup', $argv, true)) { throw new LogicException('Explicit backup/verification/apply stage required'); }
    echo 'Verified private planning backup SHA256 '.hash_file('sha256', $file)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Planning preparation failed: '.get_class($e)." (no credentials or record contents logged).\n");
    exit(1);
}
