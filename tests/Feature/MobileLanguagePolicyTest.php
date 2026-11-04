<?php

namespace Tests\Feature;

use Tests\TestCase;

final class MobileLanguagePolicyTest extends TestCase
{
    public function test_actual_central_policy_kernel_and_disabled_saved_preferences_are_rolled_back(): void
    {
        if (getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,
            'clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English'],'clubunio-languages.default'=>'de','cache.default'=>'array']);
        if (!defined('CLUBUNIO_MOBILE_LANGUAGE_POLICY_INCLUDE_ONLY')) define('CLUBUNIO_MOBILE_LANGUAGE_POLICY_INCLUDE_ONLY',true);
        require_once base_path('deploy/check-mobile-language-policy.php'); $probe=new \ClubUnioRelease\MobileLanguagePolicyProbe;
        try { $checks=$probe->run($this->app); } catch (\Throwable $error) { $this->fail($probe->stage.': '.$error::class); }
        $this->assertGreaterThanOrEqual(25,$checks);
        $this->assertSame(0,\Illuminate\Support\Facades\DB::connection('clubunio')->transactionLevel());
        fwrite(STDOUT,'Central language policy kernel checks '.$checks."; rolled back\n");
    }
}
