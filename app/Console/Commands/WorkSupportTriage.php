<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WorkSupportTriage extends Command
{
    protected $signature = 'clubunio:support-triage-work {--once}';
    protected $description = 'Process only ClubUnio support triage jobs';

    public function handle(): int
    {
        if (!config('clubunio-support.enabled') || !config('clubunio-support.triage_enabled')
            || config('clubunio-support.triage_connection') !== 'support'
            || DB::connection('clubunio')->getDatabaseName() !== 'clubunio_dev') {
            return self::FAILURE;
        }

        // Do not persist worker failures in the application's default database.
        config(['queue.failed.driver' => 'null']);

        return $this->call('queue:work', [
            'connection' => 'support', '--queue' => 'support-triage', '--sleep' => 3,
            '--timeout' => 25, '--max-time' => 1800, '--once' => $this->option('once'),
        ]);
    }
}
