<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MobileChatReactionParticipantsTest extends TestCase
{
    public function test_actual_postgresql_kernel_participant_gate_is_scoped_paginated_and_rolled_back(): void
    {
        if (getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,'cache.default'=>'array']);
        require_once base_path('deploy/check-mobile-chat-composer.php');
        $probe=new \ClubUnioRelease\ChatComposerProbe;
        try { $checks=$probe->run($this->app); }
        catch (\Throwable $error) { $this->fail($probe->stage.': '.$error::class); }
        $this->assertGreaterThanOrEqual(25,$checks);
        $this->assertSame(0,DB::connection('clubunio')->transactionLevel());
        fwrite(STDOUT,'Reaction participant kernel checks '.$checks."; rolled back\n");
    }
}
