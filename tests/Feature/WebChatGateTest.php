<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WebChatGateTest extends TestCase
{
    public function test_standalone_server_gate_uses_real_web_session_and_verified_rollback(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        require_once base_path('deploy/check-web-chat.php');
        $probe = new \ClubUnioRelease\WebChatProbe;
        $environment = $this->app['env'];
        $this->app->instance('env', 'staging');
        try {
            $checks = $probe->run($this->app);
            $this->assertGreaterThan(30, $checks);
            fwrite(STDOUT, "Web chat gate: $checks checks; idle SQL $probe->idleQueries; rollback verified.\n");
        } catch (\Throwable $error) {
            $this->fail('Web chat gate: '.$probe->stage.' ('.get_class($error).'; private details suppressed)');
        } finally { $this->app->instance('env', $environment); }
    }
}
