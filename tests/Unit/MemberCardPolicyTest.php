<?php

namespace Tests\Unit;

use App\ClubUnio\Models\{Member, MemberStatus, MemberType, Tenant};
use App\ClubUnio\Services\MemberCardService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemberCardPolicyTest extends TestCase
{
    private function fixture(): array
    {
        $tenant = new Tenant; $tenant->forceFill(['id' => 5, 'slug' => 'synthetic-card-club', 'name' => 'Synthetic Club']);
        $status = new MemberStatus; $status->forceFill(['id' => 7, 'tenant_id' => 5, 'name' => 'Not interpreted', 'active' => true, 'member_card_eligible' => true, 'ends_membership' => false]);
        $type = new MemberType; $type->forceFill(['id' => 8, 'tenant_id' => 5, 'name' => 'Synthetic type', 'active' => true]);
        $member = new Member; $member->forceFill(['id' => 9, 'tenant_id' => 5, 'first_name' => 'Synthetic', 'last_name' => 'Member', 'exit_date' => null, 'archived_at' => null]);
        $member->setRelation('status', $status); $member->setRelation('type', $type);
        return [$tenant, $member];
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_explicit_eligibility_not_the_status_name_controls_membership(): void
    {
        [$tenant, $member] = $this->fixture(); $service = new MemberCardService;
        $this->assertTrue($service->membershipValid($tenant, $member));
        $member->status->forceFill(['name' => 'Member', 'member_card_eligible' => false]);
        $this->assertFalse($service->membershipValid($tenant, $member));
        $member->status->forceFill(['name' => 'Other language text', 'member_card_eligible' => true]);
        $this->assertTrue($service->membershipValid($tenant, $member));
    }

    public function test_exit_day_stays_valid_until_midnight_in_the_club_timezone(): void
    {
        [$tenant, $member] = $this->fixture(); $service = new MemberCardService;
        $member->forceFill(['exit_date' => '2026-10-25']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:59:59', 'Europe/Zurich'));
        $this->assertTrue($service->membershipValid($tenant, $member));
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:00:00', 'Europe/Zurich'));
        $this->assertFalse($service->membershipValid($tenant, $member));
    }

    public function test_archived_inactive_missing_foreign_and_disabled_contexts_fail_closed(): void
    {
        $service = new MemberCardService;
        foreach (['archived', 'ended', 'status-inactive', 'type-inactive', 'foreign-status', 'foreign-member', 'missing-type', 'missing-status', 'disabled-club'] as $condition) {
            [$tenant, $member] = $this->fixture(); config(['clubunio-member-cards.disabled_tenant_slugs' => []]);
            match ($condition) {
                'archived' => $member->forceFill(['archived_at' => now()]),
                'ended' => $member->status->forceFill(['ends_membership' => true]),
                'status-inactive' => $member->status->forceFill(['active' => false]),
                'type-inactive' => $member->type->forceFill(['active' => false]),
                'foreign-status' => $member->status->forceFill(['tenant_id' => 6]),
                'foreign-member' => $member->forceFill(['tenant_id' => 6]),
                'missing-type' => $member->setRelation('type', null),
                'missing-status' => $member->setRelation('status', null),
                'disabled-club' => config(['clubunio-member-cards.disabled_tenant_slugs' => [$tenant->slug]]),
            };
            $this->assertFalse($service->membershipValid($tenant, $member), $condition);
        }
    }

    public function test_source_version_ignores_scan_clock_but_changes_with_brand_and_eligibility(): void
    {
        [$tenant, $member] = $this->fixture();
        $service = new class($member) extends MemberCardService {
            public array $testBrand = ['PrimaryColor' => '#bc1010', 'LogoData' => null, 'LogoMime' => null, 'LogoUrl' => null];
            public function __construct(public Member $testMember) {}
            public function enabled(): bool { return false; }
            public function member(Tenant $tenant, int $id): Member { return $this->testMember; }
            public function brand(Tenant $tenant): array { return $this->testBrand; }
        };
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'UTC')); $first = $service->snapshot($tenant, 9);
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:01:00', 'UTC')); $second = $service->snapshot($tenant, 9);
        $this->assertSame($first['SourceVersion'], $second['SourceVersion']);
        $this->assertNotSame($first['CheckedAt'], $second['CheckedAt']);
        $service->testBrand['PrimaryColor'] = '#0000ab';
        $this->assertNotSame($second['SourceVersion'], $service->snapshot($tenant, 9)['SourceVersion']);
        $member->status->forceFill(['member_card_eligible' => false]);
        $this->assertFalse($service->snapshot($tenant, 9)['Valid']);
    }

    public function test_inline_club_branding_does_not_require_public_verification_origin(): void
    {
        [$tenant] = $this->fixture();
        config(['clubunio-member-cards.public_origin' => 'http://localhost']);
        $brand = \Mockery::mock(\App\ClubUnio\Services\SignageBrandService::class);
        $brand->shouldReceive('design')->with($tenant)->andReturn(['color' => '#bc1212', 'logo' => '/images/synthetic.png']);
        $this->app->instance(\App\ClubUnio\Services\SignageBrandService::class, $brand);
        $service = new class extends MemberCardService {
            public function logoBytes(Tenant $tenant): ?string { return 'synthetic-inline-image'; }
        };
        $projection = $service->brand($tenant);
        $this->assertSame('#bc1212', $projection['PrimaryColor']);
        $this->assertSame(base64_encode('synthetic-inline-image'), $projection['LogoData']);
        $this->assertNull($projection['LogoUrl']);
    }

    public function test_origin_rejects_credentials_paths_query_and_non_https(): void
    {
        foreach (['http://synthetic.invalid', 'https://user@synthetic.invalid', 'https://user:secret@synthetic.invalid', 'https://synthetic.invalid/path', 'https://synthetic.invalid?token=x', 'https://synthetic.invalid#x'] as $origin) {
            config(['clubunio-member-cards.public_origin' => $origin]);
            try { (new MemberCardService)->origin(); $this->fail('Unsafe origin accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(503, $error->getStatusCode()); }
        }
        config(['clubunio-member-cards.public_origin' => 'https://stage.synthetic.invalid']);
        $this->assertSame('https://stage.synthetic.invalid', (new MemberCardService)->origin());
    }
}
