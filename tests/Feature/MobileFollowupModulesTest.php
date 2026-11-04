<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileFollowupModules;
use Tests\TestCase;

require_once __DIR__.'/../../deploy/mobile-followup-menu-probe.php';

class MobileFollowupModulesTest extends TestCase
{
    private function probe(bool $enabled): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rolled-back PostgreSQL required');
        config(['clubunio-mobile.enabled' => true, 'hashing.bcrypt.rounds' => 4]);
        foreach (MobileFollowupModules::CONTRACTS as [$flag]) config(['clubunio-mobile.'.$flag => $enabled]);
        $probe = new \ClubUnioRelease\FollowupMenuProbe;
        try { $checks = $probe->run($enabled); }
        catch (\Throwable $error) { $this->fail('Menu gate failed at '.$probe->stage.': '.get_class($error).($error instanceof \Illuminate\Database\QueryException ? ' SQLSTATE '.$error->getCode().' '.$error->getSql() : '')); }
        $this->addToAssertionCount($checks);
    }
    public function test_closed_foundation_disables_four_modules_and_direct_endpoints(): void { $this->probe(false); }
    public function test_enabled_original_module_menu_uses_real_scopes_and_revocations(): void { $this->probe(true); }
}
