<?php

namespace App\Console\Commands;

use App\ClubUnio\Wallet\MemberWalletService;
use Illuminate\Console\Command;

final class MemberWalletRefresh extends Command
{
    protected $signature = 'clubunio:member-wallet-refresh {--limit=200}';
    protected $description = 'Refresh installed member passes and deliver bounded certificate-authenticated update signals';

    public function handle(MemberWalletService $service): int
    {
        $this->line(json_encode($service->refreshAndNotify((int) $this->option('limit')), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
