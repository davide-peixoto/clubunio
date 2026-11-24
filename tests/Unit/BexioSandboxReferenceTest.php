<?php

namespace Tests\Unit;

use App\ClubUnio\Services\BexioSandboxDispatchService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioSandboxReferenceTest extends TestCase
{
    public function test_persisted_marker_is_used_for_both_names_and_document_types(): void
    {
        $method = new \ReflectionMethod(BexioSandboxDispatchService::class, 'reference');
        $row = (object)['kind' => 'invoice', 'reference' => '8e9bca44-cc14-4f66-8448-814535d2a0ff'];
        foreach (['invoice' => 'api_reference', 'expense' => 'vendor_ref'] as $kind => $key) {
            $row->kind = $kind;
            foreach (['clubora', 'clubunio'] as $prefix) {
                $value = $prefix.'-sandbox:'.$row->reference;
                $this->assertSame($value, $method->invoke(new BexioSandboxDispatchService, $row, [$key => $value]));
            }
        }
    }

    public function test_marker_of_another_dispatch_is_rejected(): void
    {
        $method = new \ReflectionMethod(BexioSandboxDispatchService::class, 'reference');
        $this->expectException(HttpException::class);
        $method->invoke(new BexioSandboxDispatchService, (object)['kind' => 'invoice', 'reference' => 'own'], ['api_reference' => 'clubora-sandbox:foreign']);
    }

    public function test_fixture_remark_accepts_only_exact_known_labels(): void
    {
        $method = new \ReflectionMethod(BexioSandboxDispatchService::class, 'fixtureRemark');
        foreach (['clubora', 'clubunio'] as $prefix) {
            $this->assertTrue($method->invoke(new BexioSandboxDispatchService, ['remarks' => $prefix.'-sandbox-fixture:v1:supplier'], 'Supplier'));
            $this->assertFalse($method->invoke(new BexioSandboxDispatchService, ['remarks' => $prefix.'-sandbox-fixture:v1:member'], 'Supplier'));
        }
        $this->assertFalse($method->invoke(new BexioSandboxDispatchService, ['remarks' => 'arbitrary'], 'Supplier'));
    }
}
