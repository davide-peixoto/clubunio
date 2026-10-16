<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoicePdfService
{
    public function open(Account $actor, Tenant $tenant, int $id, bool $bexio, bool $download = false)
    {
        $query = app(InvoiceAccessService::class)->visible($actor, $tenant);
        if (! $bexio) {
            $query->with(['member', 'season', 'workflowStatus', 'activeLines']);
        }
        $invoice = $query->findOrFail($id);
        if ($bexio) {
            abort_unless(preg_match('/^[1-9][0-9]{0,14}$/', (string) $invoice->bexio_invoice_id), 404, 'Keine Bexio-Rechnung zugeordnet.');
            try {
                $bytes = $this->readBexio($tenant, $invoice);
            } catch (\Throwable $error) {
                $reference = (string) Str::uuid();
                // Never log credentials, provider response bodies or document content.
                Log::warning('clubunio.invoice.pdf_failed', ['reference' => $reference, 'tenant_id' => $tenant->id,
                    'invoice_id' => $id, 'actor_id' => $actor->id, 'provider_status' => (int) $error->getCode()]);

                return response()->view('clubunio.invoices.pdf-error', [
                    'reference' => $reference,
                    'message' => match ($error->getCode()) {
                        401 => 'Der Bexio-Zugang ist ungültig oder abgelaufen. Bitte den ClubUnio-Bexio-Zugang erneuern.',
                        409 => 'Sandbox-Testbetrieb: Diese Rechnung gehört nicht zur bestätigten Testverbindung. Importierte PROD-Rechnungen können hier nicht geöffnet werden.',
                        default => 'Das Bexio-PDF ist derzeit nicht verfügbar. Bitte später erneut versuchen.',
                    },
                ], 503)->header('Cache-Control', 'private, no-store')->header('X-Frame-Options', 'SAMEORIGIN');
            }
        } else {
            abort_unless($actor->canAccessTenant($tenant) && $invoice->workflow_status_id, 403);
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('clubunio.invoices.pdf', compact('invoice', 'tenant'))->render());
            $pdf->setPaper('A4');
            $pdf->render();
            $bytes = $pdf->output();
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => strlen($bytes),
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="Rechnung-'.$id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    private function readBexio(Tenant $tenant, Invoice $invoice): string
    {
        // Activation controls synchronization/writes, never this authorized document read.
        $connection = app(BexioConnectionService::class);
        $token = $connection->accessToken($tenant);
        app(BexioSandboxFixtureService::class)->guardPdf($tenant, $invoice, $token);
        $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)
            ->withoutRedirecting()->get('https://api.bexio.com/2.0/kb_invoice/'.$invoice->bexio_invoice_id.'/pdf');
        if ($response->status() === 401) {
            $token = $connection->accessToken($tenant, $token);
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)
                ->withoutRedirecting()->get('https://api.bexio.com/2.0/kb_invoice/'.$invoice->bexio_invoice_id.'/pdf');
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Provider failure', $response->status());
        }
        $content = $response->json('content');
        $bytes = is_string($content) && strlen($content) <= 28_000_000 ? base64_decode($content, true) : false;
        if ($bytes === false || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 20_000_000) {
            throw new \RuntimeException('Invalid PDF', 502);
        }

        return $bytes;
    }
}
