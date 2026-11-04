<?php

// Fixed DEV package, deliberately not an Artisan migrator. Parent reviews/runs this.
use Illuminate\Support\Facades\{Crypt, DB};

try {
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-pitch-v1';
    $previous = '/var/www/svs-laravel/releases/20261104-clubunio-chat-delta-v1';
    $directory = '/var/www/svs-laravel/shared/pitch-backups';
    $migrations = [
        '2026_11_04_001500_pitch_planning' => '89579edb8c0ec34e93514dacf62082c869e0d36c76fef03292e522bb050c711d',
        '2026_11_04_001600_pitch_planning_notifications' => '72669480998a4367d8cedd82828e5a7a90851d7fb57339b0fc23ca371dba92fb',
        '2026_11_04_001700_pitch_weekly_areas' => '7ad6f541d5a65a59588ffc1f36520e63539c025eb0aebf525fcc0c9b0b44257a',
        '2026_11_04_001800_pitch_request_idempotency' => '6c83b9e141421bb8538d99f58688460ad5bcc834dc85b4dc55e38ef9590aaed0',
    ];
    $parents = ['tenants', 'accounts', 'training_options', 'training_slots', 'team_seasons', 'competitions',
        'message_templates', 'message_template_revisions', 'mobile_push_outbox', 'migrations'];
    $newTables = ['pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks',
        'pitch_competition_settings', 'pitch_competition_teams', 'pitch_planning_events', 'pitch_request_idempotency'];
    $args = array_slice($argv, 1);
    $stage = $args[0] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $release.'/deploy/prepare-pitch-schema.php'
        || !in_array($stage, ['--export','--verify-backup','--apply','--verify-after'], true)
        || count($args) !== ($stage === '--export' ? 2 : 4)
        || !preg_match('/^--manifest-sha256=([a-f0-9]{64})$/D', $args[1] ?? '', $manifestMatch)) throw new LogicException('Exact stage/release/manifest required');
    $checkSources = function () use ($release, $migrations, $manifestMatch): void {
        $path = '/var/www/svs-laravel/shared/pitch-release/pitch.manifest';
        if (is_link($path) || !is_file($path) || !hash_equals($manifestMatch[1], hash_file('sha256', $path))) throw new LogicException('Reviewed package manifest required');
        $seen = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (!preg_match('#^([a-f0-9]{64})  ((?:app|config|routes|database/clubunio|lang|resources/views|public/css|deploy)/[A-Za-z0-9_./-]+)$#D', $line, $m)
                || str_contains($m[2], '..') || isset($seen[$m[2]])
                || array_filter(explode('/', $m[2]), fn ($part) => $part === '' || str_starts_with($part, '.'))) throw new LogicException('Invalid manifest');
            $seen[$m[2]] = true;
            $file = $release.'/'.$m[2];
            if (is_link($file) || realpath($file) !== $file || !is_file($file) || !hash_equals($m[1], hash_file('sha256', $file))) throw new LogicException('Package source drift');
        }
        foreach ($migrations as $name => $hash) {
            $file = 'database/clubunio/'.$name.'.php';
            if (!isset($seen[$file]) || !hash_equals($hash, hash_file('sha256', $release.'/'.$file))) throw new LogicException('Pinned migration bytes required');
        }
    };
    $checkSources();
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    $guard = function () use ($db, $previous, $release): void {
        if (realpath(base_path()) !== $release || realpath('/var/www/svs-laravel/current') !== $previous
            || config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql'
            || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || array_keys(config('clubunio-languages.enabled')) !== ['de','en']) throw new LogicException('Fixed inactive DEV and DE/EN required');
        foreach (['clubunio-mobile.native_pitch_planning_enabled', 'clubunio-integrations.pitch_planning_test_mail_enabled',
            'clubunio-mobile-push.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled',
            'clubunio-mobile.ai.enabled','clubunio-mobile.training_ai.enabled','clubunio-mobile.keybox.issuance_enabled',
            'clubunio-mobile.keybox.provider_enabled'] as $flag) if (config($flag, false)) throw new LogicException('Pitch/provider flags must remain off');
    };
    $guard();
    if ($db->transactionLevel() !== 0) throw new LogicException('Standalone transaction required');
    $json = fn ($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $metadata = function ($table) use ($db): array {
        return [
            'relation' => $db->select('SELECT relkind,relpersistence,relrowsecurity,relforcerowsecurity,relacl,reloptions FROM pg_class WHERE oid=to_regclass(?)', ['clubora.'.$table]),
            'columns' => $db->select("SELECT attnum,attname,format_type(atttypid,atttypmod) AS type,attnotnull,attidentity,attgenerated,pg_get_expr(d.adbin,d.adrelid) AS default_expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND attnum>0 AND NOT attisdropped ORDER BY attnum", ['clubora.'.$table]),
            'constraints' => $db->select('SELECT conname,contype,convalidated,condeferrable,condeferred,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname', ['clubora.'.$table]),
            'indices' => $db->select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='clubora' AND tablename=? ORDER BY indexname", [$table]),
        ];
    };
    $snapshot = function () use ($db, $parents, $metadata, $json): array {
        $result = [];
        foreach ($parents as $table) {
            $rows = $db->table('clubora.'.$table)->orderBy('id')->limit(20001)->get()->all();
            if (count($rows) > 20000) throw new LogicException('Reviewed 20000-row per-parent bound exceeded');
            $result[$table] = ['metadata' => $metadata($table), 'rows' => $rows];
        }
        if (strlen($json($result)) > 64000000) throw new LogicException('Reviewed backup byte bound exceeded');
        return $result;
    };
    $fresh = function () use ($db, $newTables, $migrations): void {
        foreach (array_keys($migrations) as $name) if ($db->table('clubora.migrations')->where('migration', 'like', substr($name, 0, 17).'%')->exists()) throw new LogicException('Reserved migration number already recorded');
        foreach ($newTables as $table) if ($db->selectOne('SELECT to_regclass(?) AS relation', ['clubora.'.$table])->relation !== null) throw new LogicException('Existing/partial pitch schema; review, never resume blindly');
        if ($db->table('clubora.migrations')->whereIn('migration', array_keys($migrations))->exists()
            || $db->selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid='clubora.training_slots'::regclass AND attname='area_id' AND NOT attisdropped) AS present")->present) throw new LogicException('Existing ledger/weekly column requires review');
    };
    $session = function () use ($db): void {
        $db->statement("SET LOCAL lock_timeout = '3s'");
        $db->statement("SET LOCAL statement_timeout = '30s'");
        $db->statement("SET LOCAL TIME ZONE 'UTC'");
        $db->statement("SET LOCAL DateStyle = 'ISO, YMD'");
        $db->statement('SET LOCAL search_path = pg_catalog');
    };
    if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory
        || (fileperms($directory) & 0777) !== 0700 || fileowner($directory) !== posix_geteuid()) throw new LogicException('Precreate owned private0700 backup directory');
    if ($stage === '--export') {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); $session(); $fresh();
            $plain = $json(['format' => 'pitch-1500-1800-v1', 'release' => $release, 'created_at' => time(),
                'manifest_sha256' => $manifestMatch[1], 'migrations' => $migrations, 'parents' => $snapshot()]);
            $bytes = Crypt::encryptString($plain);
            $file = $directory.'/before-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(16)).'.cuenc';
            umask(0077);
            $handle = fopen($file, 'x+b');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) throw new LogicException('Exclusive backup required');
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) throw new LogicException('Durable encrypted backup failed');
            } finally { fclose($handle); }
            if (!hash_equals(hash('sha256', $plain), hash('sha256', Crypt::decryptString(file_get_contents($file))))) throw new LogicException('Backup decrypt/hash readback failed');
            echo $json(['backup_file' => $file, 'backup_sha256' => hash_file('sha256', $file)]).PHP_EOL;
        } finally { $db->rollBack(); }
        exit(0);
    }
    if (!preg_match('/^--backup-file=(.+)$/D', $args[2], $fileMatch)
        || !preg_match('/^--backup-sha256=([a-f0-9]{64})$/D', $args[3], $hashMatch)) throw new LogicException('Explicit backup path/hash required');
    $file = $fileMatch[1];
    if (!preg_match('#^'.preg_quote($directory, '#').'/before-[0-9]{14}-[a-f0-9]{32}\.cuenc$#D', $file)
        || !is_file($file) || is_link($file) || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600
        || fileowner($file) !== posix_geteuid() || lstat($file)['nlink'] !== 1 || filesize($file) > 130000000
        || !hash_equals($hashMatch[1], hash_file('sha256', $file))) throw new LogicException('Exact owned0600 bounded backup/hash required');
    $backup = json_decode(Crypt::decryptString(file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
    if ($backup['format'] !== 'pitch-1500-1800-v1' || $backup['release'] !== $release || $backup['migrations'] !== $migrations
        || $backup['manifest_sha256'] !== $manifestMatch[1] || array_keys($backup['parents']) !== $parents
        || $backup['created_at'] > time() || time() - $backup['created_at'] > 1800) throw new LogicException('Fresh matching backup required (30 minutes)');
    // Normalize only the three reviewed parent deltas, then compare every old byte/value.
    $verifyAfter = function () use ($db, $snapshot, $backup, $json, $migrations, $newTables): void {
        $actual = json_decode($json($snapshot()), true, 512, JSON_THROW_ON_ERROR);
        $before = $backup['parents'];
        $batch = max([0, ...array_column($before['migrations']['rows'], 'batch')]) + 1;
        $newLedger = array_values(array_filter($actual['migrations']['rows'], fn ($r) => isset($migrations[$r['migration']])));
        if (count($newLedger) !== 4 || array_column($newLedger, 'migration') !== array_keys($migrations)
            || array_unique(array_column($newLedger, 'batch')) !== [0 => $batch]) throw new LogicException('Exact four-row one-batch ledger delta required');
        $actual['migrations']['rows'] = array_values(array_filter($actual['migrations']['rows'], fn ($r) => !isset($migrations[$r['migration']])));
        $weekly = &$actual['training_slots'];
        $column = array_pop($weekly['metadata']['columns']);
        if ($column !== ['attnum' => count($weekly['metadata']['columns']) + 1, 'attname' => 'area_id', 'type' => 'bigint', 'attnotnull' => false,
            'attidentity' => '', 'attgenerated' => '', 'default_expression' => null]) throw new LogicException('Exact nullable weekly column required');
        foreach ($weekly['rows'] as &$row) { if (!array_key_exists('area_id', $row) || $row['area_id'] !== null) throw new LogicException('Existing weekly values changed'); unset($row['area_id']); } unset($row);
        $expected = ['training_slots_pitch_area_field_check' => 'CHECK (area_id IS NULL OR field_id IS NOT NULL)',
            'training_slots_pitch_area_fk' => 'FOREIGN KEY (tenant_id, field_id, area_id) REFERENCES clubora.pitch_areas(tenant_id, field_id, id)'];
        $seen = [];
        $weekly['metadata']['constraints'] = array_values(array_filter($weekly['metadata']['constraints'], function ($c) use ($expected, &$seen) {
            if (!isset($expected[$c['conname']])) return true;
            if ($c['definition'] !== $expected[$c['conname']] || !$c['convalidated'] || $c['condeferrable'] || $c['condeferred']) throw new LogicException('Exact validated weekly constraints required');
            $seen[] = $c['conname']; return false;
        }));
        if (count($seen) !== 2) throw new LogicException('Weekly constraints missing');
        $indices = array_values(array_filter($weekly['metadata']['indices'], fn ($i) => $i['indexname'] === 'training_slots_pitch_area_idx'));
        if ($indices !== [['indexname' => 'training_slots_pitch_area_idx', 'indexdef' => 'CREATE INDEX training_slots_pitch_area_idx ON clubora.training_slots USING btree (tenant_id, field_id, area_id)']]) throw new LogicException('Exact weekly index required');
        $weekly['metadata']['indices'] = array_values(array_filter($weekly['metadata']['indices'], fn ($i) => $i['indexname'] !== 'training_slots_pitch_area_idx')); unset($weekly);
        $oldCheck = array_values(array_filter($before['mobile_push_outbox']['metadata']['constraints'], fn ($c) => $c['conname'] === 'mobile_push_outbox_kind_check'));
        if (count($oldCheck) !== 1 || str_contains($oldCheck[0]['definition'], 'pitch_planning')) throw new LogicException('Expected old outbox constraint');
        $kind = "'admin_notice'::character varying]";
        $replacement = "'admin_notice'::character varying, 'pitch_planning'::character varying]";
        $wanted = str_replace($kind, $replacement, $oldCheck[0]['definition'], $replaced);
        if ($replaced !== 1) throw new LogicException('Exact old outbox definition required');
        foreach ($actual['mobile_push_outbox']['metadata']['constraints'] as &$c) if ($c['conname'] === 'mobile_push_outbox_kind_check') {
            if ($c['definition'] !== $wanted) throw new LogicException('Only pitch outbox kind append allowed');
            $c['definition'] = $oldCheck[0]['definition'];
        } unset($c);
        $oldTemplateIds = array_column($before['message_templates']['rows'], 'id');
        $added = array_values(array_filter($actual['message_templates']['rows'], fn ($r) => !in_array($r['id'], $oldTemplateIds, true)));
        $wantedTemplates = [];
        foreach ($before['tenants']['rows'] as $tenant) foreach (['de' => 'de-CH', 'en' => 'en-GB'] as $language => $locale) {
            foreach (App\ClubUnio\Services\MessageEventCatalog::all($language) as $key => $event) {
                if (!in_array($key, ['pitch.friendly.requested','pitch.friendly.approved','pitch.friendly.rejected','pitch.booking.moved','pitch.booking.created','pitch.booking.cancelled'], true)) continue;
                foreach ($event['channels'] as $channel) {
                    $variants = array_values(array_filter($before['message_templates']['rows'], fn ($r) => $r['tenant_id'] === $tenant['id'] && $r['event_key'] === $key && $r['channel'] === $channel));
                    if (array_filter($variants, fn ($r) => $r['locale'] === $locale)) continue;
                    $de = array_values(array_filter($variants, fn ($r) => $r['locale'] === 'de-CH'));
                    $wantedTemplates[$tenant['id'].'|'.$key.'|'.$channel.'|'.$locale] = [$event['title'], $event['body'], $de[0]['active'] ?? true];
                }
            }
        }
        if (count($added) !== count($wantedTemplates)) throw new LogicException('Exact missing pitch template count required');
        $newRevisionIds = [];
        foreach ($added as $r) {
            $key = $r['tenant_id'].'|'.$r['event_key'].'|'.$r['channel'].'|'.$r['locale'];
            if (!isset($wantedTemplates[$key]) || [$r['title'],$r['body'],$r['active']] !== $wantedTemplates[$key] || $r['revision'] !== 1) throw new LogicException('Unexpected new template content');
            unset($wantedTemplates[$key]);
            $revisions = array_values(array_filter($actual['message_template_revisions']['rows'], fn ($v) => $v['template_id'] === $r['id']));
            if (count($revisions) !== 1) throw new LogicException('One initial revision per new template required');
            $v = $revisions[0];
            if ([$v['tenant_id'],$v['revision'],$v['title'],$v['body'],$v['active'],$v['actor_id']] !== [$r['tenant_id'],1,$r['title'],$r['body'],$r['active'],null]) throw new LogicException('Unexpected new revision content');
            $newRevisionIds[] = $v['id'];
        }
        $actual['message_templates']['rows'] = array_values(array_filter($actual['message_templates']['rows'], fn ($r) => in_array($r['id'], $oldTemplateIds, true)));
        $actual['message_template_revisions']['rows'] = array_values(array_filter($actual['message_template_revisions']['rows'], fn ($r) => !in_array($r['id'], $newRevisionIds, true)));
        if ($json($actual) !== $json($before)) throw new LogicException('Unexpected parent rows/metadata/ledger change');
        foreach ($newTables as $table) {
            if ($db->table('clubora.'.$table)->exists()) throw new LogicException('New pitch tables must remain empty');
            if ($db->selectOne('SELECT count(*) AS n FROM pg_constraint WHERE conrelid=to_regclass(?) AND NOT convalidated', ['clubora.'.$table])->n != 0) throw new LogicException('Unvalidated pitch constraint');
            if ($db->selectOne("SELECT count(*) AS n FROM pg_class c CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault('r',c.relowner))) acl WHERE c.oid=to_regclass(?) AND acl.grantee=0", ['clubora.'.$table])->n != 0) throw new LogicException('New pitch table has PUBLIC privileges');
        }
    };
    $db->beginTransaction();
    try {
        if ($stage !== '--apply') $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $session(); $guard(); $checkSources();
        if ($stage === '--apply') {
            $db->select('SELECT pg_advisory_xact_lock(202611041500)');
            foreach ($parents as $table) $db->statement('LOCK TABLE clubora.'.$table.' IN SHARE ROW EXCLUSIVE MODE');
        }
        if ($stage === '--verify-after') $verifyAfter();
        else {
            $fresh();
            if ($json($snapshot()) !== $json($backup['parents'])) throw new LogicException('Backup stale: parent rows/metadata changed; export again');
            if ($stage === '--apply') {
                $batch = (int) $db->table('clubora.migrations')->max('batch') + 1;
                foreach ($migrations as $name => $hash) {
                    (require $release.'/database/clubunio/'.$name.'.php')->up();
                    $db->table('clubora.migrations')->insert(['migration' => $name, 'batch' => $batch]);
                }
                $verifyAfter(); $guard(); $checkSources();
            }
        }
        if ($stage === '--apply') $db->commit(); else $db->rollBack();
    } catch (Throwable $error) { if ($db->transactionLevel()) $db->rollBack(); throw $error; }
    echo 'Pitch schema stage verified: '.$stage.'; no provider activation.'.PHP_EOL;
} catch (Throwable $error) {
    // Database errors can contain bound personal rows. Emit only our fixed guard messages.
    fwrite(STDERR, 'Pitch schema stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
