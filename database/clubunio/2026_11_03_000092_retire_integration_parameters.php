<?php

use App\ClubUnio\Services\RetiredIntegrationParameters;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) { throw new LogicException('Unexpected target'); }
        $db->transaction(function () use ($db) {
            $rows = $db->table('clubora.tenant_settings')->where(function ($q) {
                foreach (RetiredIntegrationParameters::KEYS as $area => $keys) {
                    $q->orWhere(fn ($part) => $part->where('area', $area)->whereIn('key', $keys));
                }
            })->lockForUpdate()->get();
            if ($rows->isEmpty()) { return; }
            $changes = $db->table('clubora.setting_changes')->whereIn('setting_id', $rows->pluck('id'))->lockForUpdate()->get();
            $directory = storage_path('app/private/migration-backups');
            if (! is_dir($directory) && ! mkdir($directory,0700,true) && ! is_dir($directory)) { throw new RuntimeException('Backup directory unavailable'); }
            $path = $directory.'/retired-integrations-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
            $bytes = json_encode(['migration'=>'000092','settings'=>$rows,'changes'=>$changes],JSON_THROW_ON_ERROR);
            if (file_put_contents($path,$bytes,LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Backup failed'); }
            chmod($path,0600);
            if (hash_file('sha256',$path) !== hash('sha256',$bytes)) { throw new RuntimeException('Backup verification failed'); }
            $db->table('clubora.setting_changes')->whereIn('setting_id',$rows->pluck('id'))->delete();
            $db->table('clubora.tenant_settings')->whereIn('id',$rows->pluck('id'))->delete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore from private retired-integrations backup required.');
    }
};
