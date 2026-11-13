<?php

namespace Tests\Feature;

use App\ClubUnio\Services\MemberCampaignService;
use Tests\TestCase;

final class MemberCampaignContentTest extends TestCase
{
    public function test_campaign_copy_uses_its_locale_and_falls_back_per_field(): void
    {
        $campaign = (object) ['kind' => 'acknowledgement',
            'content' => '{"de":{"subject":"Vereinsbrief","body":"Einladung"},"en":{"subject":"Club letter"}}'];
        $service = app(MemberCampaignService::class);
        app()->setLocale('de');
        self::assertSame(['subject' => 'Vereinsbrief', 'body' => 'Einladung'], $service->copy($campaign));
        app()->setLocale('en');
        self::assertSame(['subject' => 'Club letter', 'body' => __('campaign.body_acknowledgement')], $service->copy($campaign));
    }

    public function test_combined_letter_view_renders_two_pdf_pages(): void
    {
        $tenant = (object) ['name' => 'Synthetic Club'];
        $url = 'https://example.invalid/campaign/test';
        $qr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class, 'outputBase64' => true,
        ])))->render($url);
        $letters = array_map(fn ($last) => ['member' => (object) ['first_name' => 'Synthetic',
            'last_name' => $last, 'street' => 'Test street', 'house_number' => '1', 'postal_code' => '4000',
            'city' => 'Test city'], 'url' => $url, 'qr' => $qr], ['One', 'Two']);
        $copy = ['subject' => 'Synthetic subject', 'body' => '<b>Plain text</b>'];
        $html = view('clubunio.public.campaign-letters-pdf', compact('letters', 'tenant', 'copy'))->render();
        self::assertStringContainsString('&lt;b&gt;Plain text&lt;/b&gt;', $html);
        $pdf = new \Dompdf\Dompdf(new \Dompdf\Options(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('a4');
        $pdf->render();
        self::assertSame(2, $pdf->getCanvas()->get_page_count());
        self::assertStringStartsWith('%PDF-', $pdf->output());
    }
}
