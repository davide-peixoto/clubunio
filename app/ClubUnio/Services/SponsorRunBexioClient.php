<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Http;

/** Fixed sandbox-only adapter; external sponsors never masquerade as members. */
class SponsorRunBexioClient
{
    public const CATALOGS = ['users' => '/3.0/users', 'languages' => '/2.0/language',
        'banks' => '/3.0/banking/accounts', 'currencies' => '/3.0/currencies',
        'payment_types' => '/2.0/payment_type', 'units' => '/2.0/unit',
        'taxes' => '/3.0/taxes', 'accounts' => '/2.0/accounts', 'countries' => '/2.0/country'];

    private function request(string $token)
    {
        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
    }

    public function verify(string $token): void
    {
        app(BexioSandboxClient::class)->verify($token);
    }

    public function read(string $token, string $path, array $query = []): array
    {
        abort_unless(in_array($path, self::CATALOGS, true)
            || preg_match('~^/2\.0/(contact(?:/[1-9][0-9]*)?|kb_invoice/[1-9][0-9]*(?:/pdf)?)$~D', $path), 422);
        $response = $this->request($token)->get('https://api.bexio.com'.$path, $query);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Sponsorenlauf: Bexio-Abfrage nicht bestätigt.', $response->status());
        }

        return $response->json();
    }

    public function catalog(string $token, string $kind): array
    {
        abort_unless(isset(self::CATALOGS[$kind]), 422);
        $query = ['limit' => 2000];
        if ($kind === 'taxes') {
            $query += ['types' => 'sales_tax', 'scope' => 'active'];
        }
        $rows = $this->read($token, self::CATALOGS[$kind], $query);
        abort_unless(array_is_list($rows) && count($rows) < 2000, 409, 'Bexio-Katalog nicht vollständig.');

        return $rows;
    }

    public function write(string $token, string $path, array $payload = []): array
    {
        abort_unless(in_array($path, ['/2.0/contact', '/2.0/kb_invoice'], true)
            || preg_match('~^/2\.0/kb_invoice/[1-9][0-9]*/(issue|send|cancel)$~D', $path), 422);
        $this->verify($token);
        try {
            $r = $this->request($token)->post('https://api.bexio.com'.$path, $payload);
            if (in_array($r->status(), [400, 401, 403, 404, 422], true)) {
                return ['state' => 'rejected'];
            }
            $data = $r->json();
            $create = in_array($path, ['/2.0/contact', '/2.0/kb_invoice'], true);
            $ok = $r->successful() && is_array($data) && ($create ? preg_match('/^[1-9][0-9]{0,14}$/D', (string) ($data['id'] ?? '')) : ($data['success'] ?? false) === true);

            return ['state' => $ok ? 'accepted' : 'uncertain', 'id' => $ok && $create ? (int) $data['id'] : null];
        } catch (\Throwable) {
            return ['state' => 'uncertain', 'id' => null];
        }
    }

    public function findInvoice(string $token, string $reference): ?array
    {
        $this->verify($token);
        $r = $this->request($token)->post('https://api.bexio.com/2.0/kb_invoice/search?limit=2000',
            [['field' => 'api_reference', 'criteria' => '=', 'value' => $reference]]);
        $rows = $r->json();
        abort_unless($r->successful() && is_array($rows) && array_is_list($rows) && count($rows) < 2000, 503);
        $matches = array_values(array_filter($rows, fn ($r) => ($r['api_reference'] ?? '') === $reference));
        abort_unless(count($matches) <= 1, 409, 'Mehrere Bexio-Belege mit gleicher Referenz.');

        return $matches ? $this->read($token, '/2.0/kb_invoice/'.(int) $matches[0]['id']) : null;
    }

    public function findContact(string $token, string $reference): ?array
    {
        $rows = $this->read($token, '/2.0/contact', ['limit' => 2000]);
        abort_unless(array_is_list($rows) && count($rows) < 2000, 409, 'Kontaktabgleich unvollständig.');
        $matches = array_values(array_filter($rows, fn ($r) => ($r['remarks'] ?? '') === $reference));
        abort_unless(count($matches) <= 1, 409, 'Mehrere Kontakte mit gleicher Referenz.');

        return $matches[0] ?? null;
    }

    public function pdf(string $token, int $id): string
    {
        $data = $this->read($token, '/2.0/kb_invoice/'.$id.'/pdf');
        $content = $data['content'] ?? null;
        $bytes = is_string($content) && strlen($content) <= 28_000_000 ? base64_decode($content, true) : false;
        abort_unless(is_string($bytes) && str_starts_with($bytes, '%PDF-') && strlen($bytes) <= 20_000_000, 502);

        return $bytes;
    }
}
