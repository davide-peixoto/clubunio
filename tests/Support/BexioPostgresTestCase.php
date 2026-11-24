<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class BexioPostgresTestCase extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $path = getenv('CLUBUNIO_BEXIO_QA_CONFIG');
        if (! $path || ! is_file($path)) {
            throw new \LogicException('Private Bexio QA connection required');
        }
        $connection = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($connection['database'] ?? '') !== 'clubunio_bexio_qa_20261124'
            || ($connection['host'] ?? '') !== '127.0.0.1' || ($connection['driver'] ?? '') !== 'pgsql') {
            throw new \LogicException('Dedicated QA database and local SSH tunnel required');
        }
        config(['database.connections.clubunio' => $connection, 'clubunio.connection' => $connection,
            'clubunio.outbound_enabled' => false, 'mail.default' => 'array', 'queue.default' => 'sync']);
        DB::purge('clubunio');
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        return $app;
    }
}
