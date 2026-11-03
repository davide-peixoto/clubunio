<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

class MimeHeaderSafetyTest extends TestCase
{
    public function test_patched_mime_rejects_header_injection_in_parameter_names(): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'receipt.pdf']);
        $this->assertStringContainsString('receipt.pdf', $header->toString());
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', ["filename\r\nX-Injected" => 'receipt.pdf']);
    }
}
