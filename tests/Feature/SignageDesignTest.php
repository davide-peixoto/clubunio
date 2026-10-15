<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\SignagePreview;
use App\ClubUnio\Services\SignageBrandService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SignageDesignTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $period;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'design-'.Str::random(10), 'name' => 'Synthetic club']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic admin', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->period = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->tenant->id, 'category' => 'period', 'code' => 'S', 'name' => 'Synthetic summer']);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function input(): array
    {
        return ['color' => '#ffffff', 'logo' => '/images/svs-logo.png', 'title' => 'Synthetic team', 'period_id' => $this->period,
            'version' => app(SignageBrandService::class)->version($this->tenant)];
    }

    public function test_design_dialog_saves_scoped_colors_logo_period_and_audit(): void
    {
        Livewire::test(SignagePreview::class)->callAction('design', data: $this->input())->assertHasNoActionErrors();
        $data = app(SignageBrandService::class)->read($this->tenant);
        $this->assertSame('#ffffff', $data['color']);
        $this->assertSame('#16191e', $data['ink']);
        $this->assertSame('/images/svs-logo.png', $data['logo']);
        $this->assertSame($this->period, $data['period_id']);
        $this->assertSame(4, $this->db->table('clubora.setting_changes')->where('tenant_id', $this->tenant->id)->count());
        $other = Tenant::create(['slug' => 'other-'.Str::random(8), 'name' => 'Other club']);
        $this->assertSame('#164b8a', app(SignageBrandService::class)->read($other)['color']);
    }

    public function test_invalid_color_or_logo_has_no_write(): void
    {
        foreach ([['logo' => 'javascript:alert(1)'], ['logo' => 'https://example.invalid/logo?secret=forbidden'], ['color' => 'red;position:fixed']] as $bad) {
            try {
                app(SignageBrandService::class)->save($this->actor, $this->tenant, array_replace($this->input(), $bad));
                $this->fail('Invalid branding accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(0, $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_stale_design_is_not_overwritten(): void
    {
        $input = $this->input();
        app(SignageBrandService::class)->save($this->actor, $this->tenant, $input);
        $this->expectException(ValidationException::class);
        app(SignageBrandService::class)->save($this->actor, $this->tenant, $input);
    }

    public function test_office_cannot_change_design(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        Livewire::test(SignagePreview::class)->assertActionHidden('design');
        $this->expectException(HttpException::class);
        app(SignageBrandService::class)->save($this->actor, $this->tenant, $this->input());
    }

    public function test_foreign_training_period_is_rejected(): void
    {
        Livewire::test(SignagePreview::class)->callAction('design', data: array_replace($this->input(), ['period_id' => 99999999]))->assertHasActionErrors(['period_id']);
        $this->assertSame(0, $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->count());
    }
}
