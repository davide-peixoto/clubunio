<?php

if (PHP_SAPI !== 'cli' || ! str_starts_with(getcwd(), '/var/www/svs-laravel/releases/')) {
    exit(1);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$row = $db->selectOne("SELECT current_database() AS db, current_schema() AS schema, current_user AS role, current_setting('server_version_num')::int AS version, (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls");
if ($row->db !== 'clubunio_dev' || $row->role !== 'clubunio_dev_app' || $row->version < 160000 || $row->version >= 170000 || ! $row->tls) {
    exit(1);
}
if (($argv[1] ?? '') === 'connection') {
    echo "DEV PDO PostgreSQL 16 TLS connection verified.\n";
    exit;
}
if ($row->schema !== 'clubunio' || $db->table('clubunio.tenants')->count() === 0
    || $db->table('clubunio.accounts')->count() === 0
    || ! $db->getSchemaBuilder()->hasTable('clubunio.mobile_push_outbox')) {
    exit(1);
}
echo "DEV clubunio schema, tenant and account reads, push outbox verified.\n";

if (($argv[1] ?? '') === 'auth') {
    $db->beginTransaction();
    try {
        $id = random_int(-2000000000, -1000000000);
        $username = 'cutover-'.bin2hex(random_bytes(12)).'@example.invalid';
        $password = bin2hex(random_bytes(32));
        $db->table('clubunio.accounts')->insert([
            'id' => $id, 'username' => $username, 'display_name' => 'DEV cutover check',
            'password' => Illuminate\Support\Facades\Hash::make($password),
            'active' => true, 'api_only' => false,
        ]);
        $guard = Illuminate\Support\Facades\Auth::guard('clubunio');
        if (! $guard->once(['username' => $username, 'password' => $password, 'active' => true])
            || (int) $guard->id() !== $id) {
            throw new LogicException('DEV authentication did not resolve the test account.');
        }
    } finally {
        $db->rollBack();
    }
    if ($db->table('clubunio.accounts')->where('id', $id)->exists()) {
        exit(1);
    }
    echo "DEV authentication verified; test account rolled back.\n";
}
