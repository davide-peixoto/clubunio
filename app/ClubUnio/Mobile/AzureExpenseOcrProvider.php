<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class AzureExpenseOcrProvider
{
    public const MODEL = 'prebuilt-receipt';
    public const VERSION = '2024-11-30';
    public function origin(): string {
        $endpoint = trim((string) config('clubunio-expense-ocr.endpoint'));
        if (! preg_match('~^https://[a-z0-9][a-z0-9-]{1,62}\.cognitiveservices\.azure\.com/?$~D', $endpoint)
            || ! is_string(config('clubunio-expense-ocr.key')) || trim(config('clubunio-expense-ocr.key')) === '') {
            throw new \RuntimeException('ocr_configuration_missing', 503);
        }
        return rtrim($endpoint, '/');
    }
    private function client() {
        return Http::withHeaders(['Ocp-Apim-Subscription-Key' => config('clubunio-expense-ocr.key')])->acceptJson()
            ->connectTimeout(5)->timeout(15)->withoutRedirecting()->withOptions(['stream' => true]);
    }
    private function path(): string { return $this->origin().'/documentintelligence/documentModels/'.self::MODEL; }
    public function start(string $bytes, string $requestId): string {
        try {
            $response = $this->client()->withHeaders(['x-ms-client-request-id' => $requestId])->post(
                $this->path().':analyze?_overload=analyzeDocument&api-version='.self::VERSION.'&pages=1', ['base64Source' => base64_encode($bytes)]);
            try {
                if ($response->status() !== 202) throw new \RuntimeException('ocr_provider_rejected', 502);
                $location = $response->header('Operation-Location');
                $prefix = preg_quote($this->path().'/analyzeResults/', '~');
                if (! preg_match('~^'.$prefix.'([a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})\?api-version='.preg_quote(self::VERSION, '~').'$~D', $location, $match)) {
                    throw new \RuntimeException('ocr_provider_reference_invalid', 502);
                }
                return strtolower($match[1]);
            } finally { $response->toPsrResponse()->getBody()->close(); }
        } catch (\Throwable) { throw new \RuntimeException('ocr_provider_start_uncertain', 502); }
    }
    public function result(string $id): array {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) throw new \RuntimeException('ocr_provider_reference_invalid', 502);
        try {
            $response = $this->client()->get($this->path().'/analyzeResults/'.$id.'?api-version='.self::VERSION);
            if ($response->status() !== 200) { $response->toPsrResponse()->getBody()->close(); throw new \RuntimeException('ocr_provider_result_failed', 502); }
            $data = $this->boundedJson($response);
            if (! in_array($data['status'] ?? null, ['notStarted','running','succeeded','failed','canceled'], true)) throw new \RuntimeException('ocr_provider_status_invalid', 502);
            return $data;
        } catch (\Throwable) { throw new \RuntimeException('ocr_provider_result_unavailable', 502); }
    }
    private function boundedJson(Response $response): array {
        $body = $response->toPsrResponse()->getBody();
        try {
            if ((int) $response->header('Content-Length') > 2 * 1024 * 1024) throw new \RuntimeException('ocr_response_oversized', 502);
            $bytes = ''; $deadline = microtime(true) + 18;
            while (! $body->eof()) {
                $bytes .= $body->read(65536);
                if (strlen($bytes) > 2 * 1024 * 1024 || microtime(true) > $deadline) throw new \RuntimeException('ocr_response_oversized', 502);
            }
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data)) throw new \RuntimeException('ocr_response_invalid', 502);
            return $data;
        } finally { $body->close(); }
    }
    private function confident(mixed $field, float $threshold): bool {
        $score = is_array($field) ? ($field['confidence'] ?? null) : null;
        return (is_float($score) || is_int($score)) && is_finite((float) $score) && $score >= $threshold && $score <= 1;
    }
    public function suggestion(array $body): array {
        $result = $body['analyzeResult'] ?? null;
        if (($body['status'] ?? '') !== 'succeeded' || ! is_array($result) || ($result['modelId'] ?? '') !== self::MODEL
            || ($result['apiVersion'] ?? '') !== self::VERSION || count($result['documents'] ?? []) !== 1
            || count($result['pages'] ?? []) !== 1 || ($result['pages'][0]['pageNumber'] ?? null) !== 1) {
            throw new \RuntimeException('ocr_result_structure_invalid', 502);
        }
        $document = $result['documents'][0]; $fields = $document['fields'] ?? [];
        if (! is_array($fields)) throw new \RuntimeException('ocr_result_fields_invalid', 502);
        $threshold = max(0.5, min(1, (float) config('clubunio-expense-ocr.min_confidence', 0.75)));
        $warnings = ['Bitte die Vorschlaege mit dem Beleg vergleichen. Nur die erste Seite wurde analysiert.'];
        $confidence = []; $amount = null; $description = [];
        $total = $fields['Total'] ?? null;
        if ($this->confident($total, $threshold) && ($total['type'] ?? '') === 'currency') {
            $currency = $total['valueCurrency']['currencyCode'] ?? null;
            $value = $total['valueCurrency']['amount'] ?? null;
            if ($currency === 'CHF' && is_numeric($value) && is_finite((float) $value) && $value > 0 && $value <= 999999999.99 && abs((float) $value - round((float) $value, 2)) < 0.000001) {
                $amount = round((float) $value, 2); $confidence[] = (float) $total['confidence'];
            } else { $warnings[] = 'Betrag oder CHF-Waehrung nicht eindeutig erkannt; bitte manuell erfassen.'; }
        } else { $warnings[] = 'Gesamtbetrag nicht sicher erkannt; bitte manuell erfassen.'; }
        $merchant = $fields['MerchantName'] ?? null;
        if ($this->confident($merchant, $threshold) && ($merchant['type'] ?? '') === 'string' && is_string($merchant['valueString'] ?? null)) {
            $name = trim($merchant['valueString']);
            if ($name !== '' && mb_strlen($name) <= 240 && ! preg_match('~[\x00-\x1f\x7f<>]|https?://~i', $name)) {
                $description[] = $name; $confidence[] = (float) $merchant['confidence'];
            }
        }
        $date = $fields['TransactionDate'] ?? null;
        if ($this->confident($date, $threshold) && ($date['type'] ?? '') === 'date'
            && is_string($date['valueDate'] ?? null) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date['valueDate'], $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $description[] = $parts[3].'.'.$parts[2].'.'.$parts[1]; $confidence[] = (float) $date['confidence'];
        }
        if ($amount === null && ! $description) $warnings[] = 'Keine verlaesslichen Vorschlaege gefunden.';
        return ['suggestion' => ['Betrag' => $amount, 'BemerkungenPos' => $description ? implode(' · ', $description) : null,
                'Gegner' => null, 'Spieltyp' => null, 'SpesenTypID' => null],
            'confidence' => $confidence ? min($confidence) : 0, 'warnings' => $warnings,
            'meta' => ['provider' => 'azure_document_intelligence', 'model' => self::MODEL, 'pages' => 1]];
    }
}
