<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{MemberCampaignService, MemberChangeService, MemberService, TenantDomainService};
use Illuminate\Http\Request;

class MemberCampaignController
{
    public function letters(Request $request, Tenant $tenant, int $campaign, MemberCampaignService $service)
    {
        $domain = app(TenantDomainService::class)->current();
        abort_if($domain && ! $domain->is($tenant), 404);
        abort_unless($service->allowed($request->user('clubunio'), $tenant), 403);
        $service->openCampaign($tenant, $campaign);
        $rows = $service->recipients($tenant, $campaign)->whereIn('channel', ['post','both'])
            ->whereNotNull('token_encrypted')->orderBy('id')->limit(1001)->get();
        abort_if($rows->isEmpty() || $rows->count() > 1000, 422, __('campaign.pdf_limit'));
        $members = $service->eligible($tenant)->whereIn('id', $rows->pluck('member_id'))->get()->keyBy('id');
        $qr = new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class, 'outputBase64' => true,
            'drawCircularModules' => false,
        ]));
        $letters = $rows->map(function ($row) use ($tenant, $service, $members, $qr) {
            $member = $members->get($row->member_id);
            abort_unless($member?->street && $member->postal_code && $member->city, 422, __('campaign.no_post'));
            $url = $service->url($tenant, $row);

            return ['member' => $member, 'url' => $url, 'qr' => $qr->render($url)];
        });
        $pdf = new \Dompdf\Dompdf(new \Dompdf\Options(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml(view('clubunio.public.campaign-letters-pdf', compact('letters', 'tenant'))->render(), 'UTF-8');
        $pdf->setPaper('a4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="campaign-letters.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function letter(Request $request, Tenant $tenant, int $campaign, int $recipient, MemberCampaignService $service)
    {
        $domain = app(TenantDomainService::class)->current();
        abort_if($domain && (int) $domain->id !== (int) $tenant->id, 404);
        abort_unless($service->allowed($request->user('clubunio'), $tenant), 403);
        $service->openCampaign($tenant, $campaign);
        $row = $service->recipients($tenant, $campaign)->whereIn('channel', ['post','both'])
            ->whereNotNull('token_encrypted')->where('id', $recipient)->firstOrFail();
        $member = $service->eligible($tenant)->findOrFail($row->member_id);
        abort_unless($member->street && $member->postal_code && $member->city, 422);
        $url = $service->url($tenant, $row);
        $qr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'outputBase64' => true,
        ])))->render($url);

        return response()->view('clubunio.public.campaign-letter', compact('tenant', 'member', 'url', 'qr'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function response(Request $request, Tenant $tenant, string $token, MemberCampaignService $campaigns, MemberChangeService $changes)
    {
        $domain = app(TenantDomainService::class)->current();
        abort_if($domain && (int) $domain->id !== (int) $tenant->id, 404);
        if ($request->isMethod('post')) {
            $request->validate(['portrait' => 'nullable|file|max:5120'], [], ['portrait' => __('photo.title')]);
            $changes->submitCampaign($tenant, $token, array_filter(
                $request->only([...MemberCampaignService::FIELDS, '_version']), fn ($value) => $value !== null && $value !== '',
            ), $request->file('portrait'));

            return $this->page($tenant, ['complete' => true]);
        }
        [, $member] = $campaigns->invitation($tenant, $token);

        return $this->page($tenant, ['complete' => false, 'version' => MemberService::version($member)]);
    }

    private function page(Tenant $tenant, array $data)
    {
        return response()->view('clubunio.public.campaign-response', ['tenant' => $tenant] + $data)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
