<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $options = getopt('', ['preview', 'rollback-test', 'apply', 'expected:', 'actor:']);
    if (count(array_intersect(['preview', 'rollback-test', 'apply'], array_keys($options))) !== 1) throw new LogicException('Explicit mode required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Exact database required');
    if (isset($options['apply']) && (PHP_OS_FAMILY !== 'Linux' || config('app.url') !== 'https://dev.clubunio.ch')) throw new LogicException('ClubUnio server required');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->statement("SET LOCAL statement_timeout = '30s'");
        if (isset($options['preview'])) $db->statement('SET TRANSACTION READ ONLY');
        $q = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach');
        $tenant = isset($options['preview']) ? $q->sole() : $q->lockForUpdate()->sole();
        $snapshot = [];
        foreach (['training_options', 'training_slots', 'team_seasons', 'pitch_areas', 'pitch_training_exceptions',
            'pitch_blocks', 'pitch_requests', 'competitions', 'field_availabilities'] as $table) {
            $snapshot[$table] = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id')->get()->all();
        }
        $plain = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $plain);
        $slots = collect($snapshot['training_slots'])->whereNotNull('field_id')->whereNull('area_id');
        $report = ['snapshot_sha256' => $hash, 'candidates' => $slots->count(),
            'without_field' => collect($snapshot['training_slots'])->whereNull('field_id')->count(), 'committed' => false];
        if (isset($options['preview'])) {
            echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
        } else {
            if (!hash_equals($hash, $options['expected'] ?? '')) throw new LogicException('Fresh reviewed snapshot required');
            if ($snapshot['pitch_training_exceptions']) throw new LogicException('Review dated exceptions separately');
            $actor = $db->table('clubora.accounts as a')->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
                ->where('ta.tenant_id', $tenant->id)->where('ta.role', 'admin')->where('a.active', true)->where('a.api_only', false)
                ->where('a.username', $options['actor'] ?? '')->value('a.id');
            if (!$actor) throw new LogicException('Named existing admin required');
            $backup = null;
            if (isset($options['apply'])) {
                $directory = '/var/www/svs-laravel/shared/backups';
                if (realpath($directory) !== $directory) throw new LogicException('Private backup path required');
                $backup = $directory.'/training-halves-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(8)).'.cuenc';
                $sealed = Illuminate\Support\Facades\Crypt::encryptString($plain);
                $mask = umask(0077);
                try {
                    $handle = fopen($backup, 'x');
                    if (!$handle) throw new LogicException('Exclusive backup required');
                    try { if (fwrite($handle, $sealed) !== strlen($sealed) || !fflush($handle) || !fsync($handle)) throw new LogicException('Incomplete backup'); }
                    finally { fclose($handle); }
                } finally { umask($mask); }
                if (is_link($backup) || (fileperms($backup) & 0077) !== 0
                    || !hash_equals($plain, Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($backup)))) throw new LogicException('Backup readback failed');
            }
            $audit = function ($table, $id, $before, $after) use ($db, $tenant, $actor) {
                $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor, 'object_type' => $table,
                    'object_id' => $id, 'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
            };
            $created = [];
            foreach (collect($snapshot['training_options'])->where('category', 'field') as $field) {
                $areas = $db->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)->where('field_id', $field->id)->get();
                if ($areas->isNotEmpty()) continue; // Never reinterpret custom geometry.
                foreach (['A' => 0, 'B' => .5] as $name => $x) {
                    $data = ['tenant_id' => $tenant->id, 'field_id' => $field->id, 'name_de' => 'Hälfte '.$name, 'name_en' => 'Half '.$name,
                        'x' => $x, 'y' => 0, 'width' => .5, 'height' => 1];
                    $id = $db->table('clubora.pitch_areas')->insertGetId($data);
                    $audit('pitch_areas', $id, null, $data);
                    $created[] = $id;
                }
            }
            $changed = [];
            $slots = $slots->sort(function ($a, $b) {
                return [(int)!$a->active, $a->starts_at, $a->id] <=> [(int)!$b->active, $b->starts_at, $b->id];
            });
            foreach ($slots as $slot) {
                $area = app(App\ClubUnio\Services\PitchWeeklyAreas::class)->suggestedHalf($tenant, (array)$slot, $slot->id);
                if (!$area) throw new LogicException('Expected a half for every field-bound slot');
                $after = ['area_id' => $area, 'revision' => $slot->revision + 1];
                $count = $db->table('clubora.training_slots')->where('tenant_id', $tenant->id)->where('id', $slot->id)
                    ->where('revision', $slot->revision)->whereNull('area_id')->update($after + ['updated_at' => now()]);
                if ($count !== 1) throw new LogicException('Concurrent change');
                $audit('training_slots', $slot->id, ['area_id' => null, 'revision' => $slot->revision], $after);
                $fresh = (array)$db->table('clubora.training_slots')->where('id', $slot->id)->first();
                foreach ((array)$slot as $key => $value) if (!in_array($key, ['area_id', 'revision', 'updated_at'], true) && $fresh[$key] !== $value) throw new LogicException('Unexpected field change');
                $changed[] = ['id' => $slot->id, 'area_id' => $area];
            }
            foreach (['training_options', 'team_seasons', 'pitch_training_exceptions', 'pitch_blocks', 'pitch_requests', 'competitions', 'field_availabilities'] as $table) {
                $fresh = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id')->get()->all();
                if (json_encode($fresh, JSON_THROW_ON_ERROR) !== json_encode($snapshot[$table], JSON_THROW_ON_ERROR)) throw new LogicException('Protected data changed');
            }
            if (isset($options['apply'])) $db->commit(); else $db->rollBack();
            echo json_encode(array_replace($report, ['areas_created' => $created, 'changed' => $changed, 'committed' => isset($options['apply']),
                'backup_file' => $backup, 'backup_sha256' => $backup ? hash_file('sha256', $backup) : null]), JSON_THROW_ON_ERROR).PHP_EOL;
        }
    } finally { if ($db->transactionLevel()) $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Training correction stopped: '.get_class($error).' (private details suppressed).'.PHP_EOL);
    exit(1);
}
