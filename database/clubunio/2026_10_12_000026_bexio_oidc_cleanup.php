<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $keys = ['BEXIO_TOKEN', 'BEXIO_REFRESH_TOKEN', 'BEXIO_ACCESS_TOKEN_EXPIRES_AT',
            'BEXIO_TOKEN_ENDPOINT', 'BEXIO_PROVIDER_URL', 'BEXIO_REDIRECT_URI', 'BEXIO_CRON_TOKEN'];
        $rows = $db->table('clubora.tenant_settings')->where('area', 'bexio')->whereIn('key', $keys)->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return;
        }
        $changes = $db->table('clubora.setting_changes')->whereIn('setting_id', $rows->pluck('id'))->get();
        // Preserve encrypted values and audit metadata outside the public web root before removal.
        $directory = storage_path('app/private/migration-backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create private migration backup directory');
        }
        $path = $directory.'/bexio-oidc-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
        $bytes = json_encode(['settings' => $rows, 'changes' => $changes], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Could not back up retired Bexio settings');
        }
        chmod($path, 0600);
        $db->table('clubora.setting_changes')->whereIn('setting_id', $rows->pluck('id'))->delete();
        $db->table('clubora.tenant_settings')->whereIn('id', $rows->pluck('id'))->delete();
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore from private encrypted backup required; never reactivate copied refresh tokens automatically.');
    }
};
