<?php

namespace Tests\Unit;

use App\ClubUnio\Services\ErpNextQrReference;
use PHPUnit\Framework\TestCase;

class ErpNextQrReferenceTest extends TestCase
{
    public function test_reference_is_stable_and_scoped_to_invoice_tenant_and_environment(): void
    {
        $references = array_map(ErpNextQrReference::fromInvoiceReference(...), [
            'clubunio:sandbox:1:invoice:1056',
            'clubunio:sandbox:1:invoice:1057',
            'clubunio:sandbox:2:invoice:1056',
            'clubunio:other:1:invoice:1056',
        ]);

        $this->assertSame('935230776416086123106912978', $references[0]);
        $this->assertCount(4, array_unique($references));
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^9[0-9]{26}$/D', $reference);
        }
    }
}
