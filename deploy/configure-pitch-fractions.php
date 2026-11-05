<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $options = getopt('', ['preview', 'rollback-test', 'apply', 'verify', 'expected:', 'actor:', 'root:']);
    if (count(array_intersect(['preview', 'rollback-test', 'apply', 'verify'], array_keys($options))) !== 1) throw new LogicException('Explicit mode required');
    $root = isset($options['root']) ? realpath($options['root']) : dirname(__DIR__);
    if (isset($options['root']) && ($options['root'] !== '/var/www/svs-laravel/current' || !str_starts_with($root ?: '', '/var/www/svs-laravel/releases/'))) throw new LogicException('Active ClubUnio release required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Exact database required');
    if (isset($options['apply']) && (PHP_OS_FAMILY !== 'Linux' || config('app.url') !== 'https://dev.clubunio.ch')) throw new LogicException('ClubUnio server required');
    $presets = require __DIR__.'/pitch-fraction-presets.php';
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->statement("SET LOCAL statement_timeout = '30s'");
        $readOnly = isset($options['preview']) || isset($options['verify']);
        if ($readOnly) $db->statement('SET TRANSACTION READ ONLY');
        $q = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach');
        $tenant = $readOnly ? $q->sole() : $q->lockForUpdate()->sole();
        $snapshot = [];
        foreach (['training_options', 'pitch_areas', 'training_slots', 'pitch_training_exceptions', 'pitch_blocks',
            'pitch_requests', 'competitions', 'pitch_competition_settings', 'field_availabilities'] as $table) {
            $snapshot[$table] = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id')->get()->all();
        }
        $plain = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $plain);
        $fields = collect($snapshot['training_options'])->where('category', 'field')->where('active', true);
        if ($fields->isEmpty()) throw new LogicException('No active fields');
        $pending = function () use ($db, $tenant, $fields, $presets) {
            $missing = [];
            foreach ($fields as $field) {
                $areas = $db->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)->where('field_id', $field->id)->get();
                foreach ($presets as $preset) {
                    $match = $areas->first(function ($area) use ($preset) {
                        foreach (['x', 'y', 'width', 'height'] as $key) {
                            if (round((float)$area->$key, 6) !== round((float)$preset[$key], 6)) return false;
                        }
                        return true;
                    });
                    if ($match) continue; // Preserve equivalent custom areas and their labels.
                    if ($areas->contains(fn ($area) => $area->name_de === $preset['name_de'] || $area->name_en === $preset['name_en'])) throw new LogicException('Conflicting area label requires review');
                    $missing[] = ['tenant_id' => $tenant->id, 'field_id' => $field->id] + $preset;
                }
            }
            return $missing;
        };
        $missing = $pending();
        $report = ['snapshot_sha256' => $hash, 'field_ids' => $fields->pluck('id')->values()->all(),
            'areas_to_create' => count($missing), 'committed' => false];
        if (!$readOnly) {
            if (!hash_equals($hash, $options['expected'] ?? '')) throw new LogicException('Fresh reviewed snapshot required');
            $actor = $db->table('clubora.accounts as a')->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
                ->where('ta.tenant_id', $tenant->id)->where('ta.role', 'admin')->where('a.active', true)->where('a.api_only', false)
                ->where('a.username', $options['actor'] ?? '')->value('a.id');
            if (!$actor) throw new LogicException('Named existing admin required');
            $backup = null;
            if (isset($options['apply']) && $missing) {
                $directory = '/var/www/svs-laravel/shared/backups';
                if (realpath($directory) !== $directory) throw new LogicException('Private backup path required');
                $backup = $directory.'/pitch-fractions-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(8)).'.cuenc';
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
            $created = [];
            foreach ($missing as $data) {
                $id = $db->table('clubora.pitch_areas')->insertGetId($data);
                $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor,
                    'object_type' => 'pitch_areas', 'object_id' => $id, 'before_value' => null,
                    'after_value' => json_encode($data, JSON_THROW_ON_ERROR)]);
                $created[] = $id;
            }
            if ($pending() !== []) throw new LogicException('Preset completeness/idempotency failed');
            foreach ($snapshot as $table => $before) {
                $query = $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id');
                if ($table === 'pitch_areas') $query->whereNotIn('id', $created);
                if (json_encode($query->get()->all(), JSON_THROW_ON_ERROR) !== json_encode($before, JSON_THROW_ON_ERROR)) throw new LogicException('Existing data changed');
            }
            if (isset($options['apply'])) $db->commit(); else $db->rollBack();
            $report += ['areas_created' => $created, 'idempotency_checked' => true, 'existing_data_unchanged' => true,
                'backup_file' => $backup, 'backup_sha256' => $backup ? hash_file('sha256', $backup) : null];
            $report['committed'] = isset($options['apply']);
        } elseif (isset($options['verify'])) {
            if ($missing) throw new LogicException('Missing presets');
            // Exercise the same projections/options consumed by Web and the mobile API.
            $projection = app(App\ClubUnio\Services\PitchPlanningProjection::class)->fields($tenant);
            foreach ($fields as $field) {
                $projected = collect($projection)->firstWhere('id', $field->id);
                $labels = app(App\ClubUnio\Services\PitchWeeklyAreas::class)->options($tenant, $field->id);
                if (!$projected) throw new LogicException('Missing projected field');
                foreach ($projected['areas'] as $area) {
                    if (!isset($labels[$area['id']]) || !$area['name_de'] || !$area['name_en']) throw new LogicException('Missing localized area');
                }
                $report['projected_area_counts'][$field->id] = count($projected['areas']);
            }
        }
        echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { if ($db->transactionLevel()) $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Pitch fraction configuration stopped: '.get_class($error).' (private details suppressed).'.PHP_EOL);
    exit(1);
}
