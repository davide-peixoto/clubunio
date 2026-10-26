<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;

class TrainingPlanPdfService
{
    public function open(Account $actor, Tenant $tenant, int $plan)
    {
        app(TrainingPlanService::class)->authorizeOperation($actor, $tenant, 'download');
        $row = app(TrainingPlanService::class)->get($actor, $tenant, $plan);
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('clubunio.training.pdf', compact('row', 'tenant'))->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="Training-'.$plan.'.pdf"', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }
}
