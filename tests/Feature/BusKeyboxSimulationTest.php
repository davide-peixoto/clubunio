<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BusKeyboxAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusKeyboxSimulationTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $bus;

    private int $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'keybox-'.Str::random(8), 'name' => 'Fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(10), 'display_name' => 'Fixture', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->bus = $this->db->table('clubora.buses')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture bus']);
        foreach (['GENEHMIGT', 'ANGEFRAGT'] as $status) {
            $this->db->table('clubora.bus_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $status, 'name' => $status]);
        }
        $this->reservation = $this->db->table('clubora.bus_reservations')->insertGetId(['tenant_id' => $this->tenant->id, 'bus_id' => $this->bus, 'status' => 'GENEHMIGT',
            'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->rollBack();
        }parent::tearDown();
    }

    public function test_code_is_encrypted_idempotent_and_never_a_real_pin(): void
    {
        $service = app(BusKeyboxAccessService::class);
        $service->configure($this->actor, $this->tenant, null, ['revision' => 0, 'bus_id' => $this->bus, 'name' => 'Simulation', 'active' => true]);
        $code = $service->issueSimulation($this->actor, $this->tenant, $this->reservation, 1);
        $this->assertStringStartsWith('TEST-', $code);
        $this->assertSame($code, $service->issueSimulation($this->actor, $this->tenant, $this->reservation, 1));
        $cipher = $this->db->table('clubora.bus_keybox_codes')->where('tenant_id', $this->tenant->id)->value('pin_cipher');
        $this->assertNotSame($code, $cipher);
        $service->invalidate($this->actor, $this->tenant, $this->reservation);
        $this->assertNull($service->inspect($this->actor, $this->tenant, $this->reservation)['testCode']);
        Http::assertNothingSent();
    }

    public function test_expired_or_unapproved_reservation_cannot_issue(): void
    {
        $service = app(BusKeyboxAccessService::class);
        $service->configure($this->actor, $this->tenant, null, ['revision' => 0, 'bus_id' => $this->bus, 'name' => 'Simulation', 'active' => true]);
        $this->db->table('clubora.bus_reservations')->where('id', $this->reservation)->update(['status' => 'ANGEFRAGT']);
        $this->expectException(HttpException::class);
        $service->issueSimulation($this->actor, $this->tenant, $this->reservation, 1);
    }

    public function test_box_change_invalidates_old_codes(): void
    {
        $service = app(BusKeyboxAccessService::class);
        $box = $service->configure($this->actor, $this->tenant, null, ['revision' => 0, 'bus_id' => $this->bus, 'name' => 'Simulation', 'active' => true]);
        $service->issueSimulation($this->actor, $this->tenant, $this->reservation, 1);
        $service->configure($this->actor, $this->tenant, $box->id, ['revision' => $box->revision, 'bus_id' => $this->bus, 'name' => 'Simulation', 'active' => false]);
        $this->assertFalse($service->inspect($this->actor, $this->tenant, $this->reservation)['simulationEnabled']);
        $this->assertSame('invalidated',$this->db->table('clubora.bus_keybox_codes')->where('tenant_id',$this->tenant->id)->value('state'));
    }
}
