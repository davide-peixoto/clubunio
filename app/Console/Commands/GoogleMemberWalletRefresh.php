<?php

namespace App\Console\Commands;

use App\ClubUnio\Wallet\GoogleMemberWalletService;
use Illuminate\Console\Command;

final class GoogleMemberWalletRefresh extends Command
{
    protected $signature = 'clubunio:google-wallet-refresh {--limit=100}';
    protected $description = 'Synchronize issued Google member passes with authoritative membership state';

    public function handle(GoogleMemberWalletService $service): int
    {
        $result = $service->refresh((int) $this->option('limit'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
