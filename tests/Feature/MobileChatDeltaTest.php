<?php

namespace Tests\Feature;

use Tests\TestCase;

final class MobileChatDeltaTest extends TestCase
{
    public function test_real_kernel_delta_snapshot_races_pagination_scope_and_idle_with_verified_rollback(): void
    {
        if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>true,
            'clubunio-mobile.bootstrap.origins'=>['https://dev.clubunio.ch'],'cache.default'=>'array']);
        if(!defined('CLUBUNIO_CHAT_DELTA_INCLUDE_ONLY')) define('CLUBUNIO_CHAT_DELTA_INCLUDE_ONLY',true);
        require_once base_path('deploy/check-mobile-chat-delta.php'); $probe=new \ClubUnioRelease\MobileChatDeltaProbe;
        try { $checks=$probe->run($this->app); }
        catch(\Throwable $error) { throw new \RuntimeException($probe->stage.': '.$error->getMessage(),0,$error); }
        $this->assertGreaterThanOrEqual(50,$checks);
        $this->assertLessThanOrEqual(16,$probe->idleQueries);
        $this->assertSame(0,\Illuminate\Support\Facades\DB::connection('clubunio')->transactionLevel());
        fwrite(STDOUT,'Chat delta kernel checks '.$checks.' idleSQL='.$probe->idleQueries."; rollback verified\n");
    }
}
