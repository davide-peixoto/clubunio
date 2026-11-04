<?php

namespace Tests\Feature;

use Tests\TestCase;

final class MobileDirectContactsTest extends TestCase
{
    public function test_directional_search_reply_privacy_and_constant_query_budget_with_verified_rollback(): void
    {
        if (getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-mobile.enabled'=>true,
            'clubunio-mobile.bootstrap.enabled'=>true,'clubunio-mobile.bootstrap.origins'=>['https://dev.clubunio.ch'],'cache.default'=>'array']);
        if (!defined('CLUBUNIO_DIRECT_CONTACTS_INCLUDE_ONLY')) define('CLUBUNIO_DIRECT_CONTACTS_INCLUDE_ONLY',true);
        require_once base_path('deploy/check-mobile-direct-contacts.php');
        $probe=new \ClubUnioRelease\MobileDirectContactsProbe;
        try { $checks=$probe->run($this->app); }
        catch (\Throwable $error) { throw new \RuntimeException($probe->stage.': '.$error->getMessage(),0,$error); }
        $this->assertGreaterThanOrEqual(60,$checks);
        $this->assertSame($probe->queryBudget[0],$probe->queryBudget[1]);
        $this->assertSame(0,\Illuminate\Support\Facades\DB::connection('clubunio')->transactionLevel());
        fwrite(STDOUT,'Direct contact kernel checks '.$checks.' searches='.json_encode($probe->searchBudgets,JSON_THROW_ON_ERROR)."; rollback verified\n");
    }
}
