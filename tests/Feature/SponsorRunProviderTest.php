<?php

namespace Tests\Feature;

use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\SponsorRunBexioClient;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SponsorRunProviderTest extends TestCase
{
    public function test_foreign_company_prevents_any_write(): void
    {
        Http::preventStrayRequests();
        $this->mock(BexioSandboxClient::class, fn ($m) => $m->shouldReceive('verify')->once()->andThrow(new \RuntimeException('Wrong company')));
        try {
            app(SponsorRunBexioClient::class)->write('fake', '/2.0/contact', ['name_1' => 'Synthetic']);
            $this->fail('Foreign company accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Wrong company', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_unconfirmed_response_is_not_success_and_has_no_automatic_retry(): void
    {
        $this->mock(BexioSandboxClient::class, fn ($m) => $m->shouldReceive('verify')->once());
        Http::fake(['https://api.bexio.com/2.0/kb_invoice' => Http::response(['id' => 0], 200)]);
        $result = app(SponsorRunBexioClient::class)->write('fake', '/2.0/kb_invoice', []);
        $this->assertSame('uncertain', $result['state']);
        $this->assertNull($result['id']);
        Http::assertSentCount(1);
    }

    public function test_exact_reference_search_and_pdf_bytes_are_validated(): void
    {
        $this->mock(BexioSandboxClient::class, fn ($m) => $m->shouldReceive('verify')->once());
        Http::fake([
            'https://api.bexio.com/2.0/kb_invoice/search*' => Http::response([['id' => 88, 'api_reference' => 'exact']]),
            'https://api.bexio.com/2.0/kb_invoice/88' => Http::response(['id' => 88, 'api_reference' => 'exact']),
            'https://api.bexio.com/2.0/kb_invoice/88/pdf' => Http::response(['content' => base64_encode('%PDF-1.4 fixture')]),
        ]);
        $client = app(SponsorRunBexioClient::class);
        $this->assertSame(88, $client->findInvoice('fake', 'exact')['id']);
        $this->assertSame('%PDF-1.4 fixture', $client->pdf('fake', 88));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/search') && $request->data() === [['field' => 'api_reference', 'criteria' => '=', 'value' => 'exact']]);
    }

    public function test_bank_payment_endpoint_is_not_available(): void
    {
        Http::preventStrayRequests();
        try {
            app(SponsorRunBexioClient::class)->write('fake', '/2.0/kb_invoice/88/payment', []);
            $this->fail('Unsupported payment endpoint accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }
}
