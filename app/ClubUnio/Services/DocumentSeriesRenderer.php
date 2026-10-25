<?php

namespace App\ClubUnio\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DocumentSeriesRenderer
{
    public const FIELDS = ['Mitglied.Vorname', 'Mitglied.Nachname', 'Mitglied.Adresse', 'Mitglied.Email',
        'Team.Name', 'Dokument.Datum', 'Schluessel.Nummer', 'Schluessel.Grundnummer', 'Dokument.Verantwortlicher'];

    public const EXTRA = ['Schluessel.Nummer', 'Schluessel.Grundnummer', 'Dokument.Verantwortlicher'];

    public function definition(array $input): array
    {
        $d = Validator::make($input, [
            'name' => 'required|string|max:160', 'title' => 'required|string|max:160', 'text' => 'required|string|max:15000',
            'type_id' => 'required|integer|min:1', 'signature_required' => 'required|boolean',
            'items' => 'present|array|max:40', 'items.*' => 'array:description,reference,quantity,price_cents,size,initials,brand',
            'items.*.description' => 'required|string|max:240', 'items.*.reference' => 'nullable|string|max:80',
            'items.*.quantity' => 'required|integer|min:1|max:999', 'items.*.price_cents' => 'required|integer|min:0|max:100000000',
            'items.*.size' => 'nullable|string|max:80', 'items.*.initials' => 'nullable|string|max:80', 'items.*.brand' => 'nullable|string|max:80',
        ])->validate();
        $d['signature_required'] = (bool) $d['signature_required'];
        $d['type_id'] = (int) $d['type_id'];
        $d['items'] = array_values($d['items']);
        foreach ($d['items'] as &$item) {
            $item['quantity'] = (int) $item['quantity'];
            $item['price_cents'] = (int) $item['price_cents'];
        }
        $this->personalize($d, array_fill_keys(self::FIELDS, 'Test'));

        return $d;
    }

    public function substitute(string $text, array $values): string
    {
        // Parse only the template, never re-interpret braces supplied by a member.
        $remainder = preg_replace('/\{\{[^{}]+\}\}/u', '', $text);
        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            throw ValidationException::withMessages(['template' => 'Ungültiger Platzhalter.']);
        }

        return preg_replace_callback('/\{\{([^{}]+)\}\}/u', function ($m) use ($values) {
            if (! in_array($m[1], self::FIELDS, true) || trim((string) ($values[$m[1]] ?? '')) === '') {
                throw ValidationException::withMessages(['template' => 'Unbekannter oder fehlender Platzhalter: '.$m[1]]);
            }

            return (string) $values[$m[1]];
        }, $text);
    }

    public function personalize(array $d, array $values): array
    {
        foreach (['title', 'text'] as $field) {
            $d[$field] = $this->substitute($d[$field], $values);
        }
        abort_if(mb_strlen($d['title']) > 200, 422, 'Personalisierter Titel zu lang.');
        foreach ($d['items'] as &$item) {
            foreach (['description', 'reference', 'size', 'initials', 'brand'] as $field) {
                $item[$field] = $this->substitute($item[$field] ?? '', $values);
                abort_if(mb_strlen($item[$field]) > ($field === 'description' ? 300 : 160), 422);
            }
        }

        return $d;
    }

    public static function hash(array $value): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }
            foreach ($a as &$v) {
                if (is_array($v)) {
                    $v = $sort($v);
                }
            }

            return $a;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    public function html(array $snapshot): string
    {
        return view('clubunio.document-series.pdf', ['snapshot' => $snapshot])->render();
    }

    public function pdf(array $snapshot): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($snapshot), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
