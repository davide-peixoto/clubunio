<?php

namespace App\Console\Commands;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\AccountSecurity;
use Illuminate\Console\Command;

final class InviteAccountSecurity extends Command
{
    protected $signature = 'clubunio:security-invite {account : Numeric account ID} {--recovery : Replace factors after independent identity verification}';
    protected $description = 'Issue a short-lived first-time setup code after independent identity verification';

    public function handle(AccountSecurity $security): int
    {
        if (!$security->enabled() || !ctype_digit((string)$this->argument('account'))
            || !$this->confirm('Identity independently verified and a secure delivery channel agreed?')) return self::FAILURE;
        $actor = Account::whereKey((int)$this->argument('account'))->firstOrFail();
        $this->line($security->invitation($actor, (bool)$this->option('recovery')));
        return self::SUCCESS;
    }
}
