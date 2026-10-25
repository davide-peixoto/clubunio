<?php

namespace App\ClubUnio\Services;

/** Cleaned legacy texts and prices only; no personal samples or source files. */
class DocumentSeriesDefaults
{
    public static function all(): array
    {
        $player = 'Bei Beendigung der Angehörigkeit in der Mannschaft Herren 1 oder Frauen 1 vor Ablauf von zwei vollständig absolvierten Saisons, beginnend ab der Materialabgabe, muss das abgegebene Material anteilsmässig bezahlt werden. Artikel ohne Initialen können alternativ zurückgegeben werden, sofern sie sich in einem ordentlichen Zustand befinden und lediglich normale Gebrauchsspuren aufweisen.'
            ."\n\n".'Der/die Spieler/in ist verpflichtet, die abgegebene Vereinsbekleidung während des Trainings- und Matchbetriebs zu tragen. Bei Spielen oder Turnieren ist dabei das Polo-Shirt und/oder die Kapuzenjacke zu tragen.'
            ."\n\n".'Die Kosten für das abgegebene Set übernimmt der Verein. Die Kosten für die aufgebrachten Initialen gehen jedoch zulasten des Spielers/der Spielerin und werden separat in Rechnung gestellt.';
        $trainer = 'Bei Beendigung des Traineramtes vor Ablauf von zwei vollständig absolvierten Saisons muss das abgegebene Material anteilsmässig bezahlt werden. Artikel ohne Initialen können alternativ zurückgegeben werden, sofern sie sich in einem ordentlichen Zustand befinden und lediglich normale Gebrauchsspuren aufweisen.'
            ."\n\n".'Der/die Trainer/in ist verpflichtet, die abgegebene Vereinsbekleidung während des Trainings- und Matchbetriebs zu tragen. Bei Spielen und Turnieren ist dabei das Polo-Shirt und/oder die Kapuzenjacke mit Sponsorenaufdruck zu tragen.'
            ."\n\n".'Die Kosten für das abgegebene Set übernimmt der Verein. Die Kosten für die aufgebrachten Initialen gehen jedoch zulasten des Trainers/der Trainerin und werden separat in Rechnung gestellt.';
        $confirmation = "\n\nInitialen: CHF 5.00 pro Stück (separate Rechnung).\n\nDer/die Unterzeichnende bestätigt den Erhalt des oben aufgeführten Materials und die Kenntnisnahme und Akzeptanz sämtlicher oben aufgeführten Bedingungen.";
        $items = fn ($rows) => array_map(fn ($r) => ['reference' => $r[0], 'description' => $r[1], 'quantity' => 1, 'price_cents' => $r[2]], $rows);

        return [
            'legacy-player' => ['name' => 'Materialübergabe Spieler H1/F1', 'title' => 'Materialübergabe', 'type_code' => 'MATERIALQUITTUNG', 'signature_required' => true, 'text' => $player.$confirmation, 'items' => $items([
                ['6126-105', 'Trainings-Shirt rot/weiss', 3550], ['4400-08', 'Trainings-Shorts schwarz', 1260], ['8626-105', 'Trainings-ZipTop rot/weiss', 4600], ['8470-800', 'Trainingshose schwarz', 3500], ['6826-105', 'Kapuzenjacke rot/weiss', 6000], ['6326-105', 'Polo-Shirt rot/weiss', 3900],
            ])],
            'legacy-trainer' => ['name' => 'Materialübergabe Trainer', 'title' => 'Materialübergabe', 'type_code' => 'MATERIALQUITTUNG', 'signature_required' => true, 'text' => $trainer.$confirmation, 'items' => $items([
                ['6126-035', 'Trainings-Shirt off white/schwarz', 3550], ['8370-800', 'Trainings-Shorts schwarz', 3150], ['8626-035', 'Trainings-Top off white/schwarz', 4600], ['8470-800', 'Trainingshose schwarz', 3850], ['6826-035', 'Kapuzenjacke off white/schwarz', 6000], ['6326-035', 'Polo-Shirt off white/schwarz', 3900], ['7403-800', 'Regenjacke schwarz', 7400],
            ])],
            'legacy-key' => ['name' => 'Schlüsselübergabe', 'title' => 'Schlüsselübergabe', 'type_code' => 'SCHLUESSELQUITTUNG', 'signature_required' => true, 'items' => [],
                'text' => "Adresse: {{Mitglied.Adresse}}\nSchlüssel Grundnummer: {{Schluessel.Grundnummer}}\nSchlüssel Nummer: {{Schluessel.Nummer}}\nAnzahl: 1 Schlüssel\n\nBei Verlust des Schlüssels ist der Trainer/die Trainerin haftbar. Die Gemeinde ersetzt den Schlüssel. Die Kosten gehen zu Lasten des Trainers/der Trainerin.\nEin allfälliger Verlust ist dem Verein unverzüglich zu melden.\nAuf Aufforderung des Vorstandes ist der Schlüssel jederzeit umgehend zu retournieren.\nBei Beendigung der Tätigkeit als Trainer/in (oder Funktion im Verein) ist der Schlüssel unaufgefordert zurückzugeben.\n\nDer/die Unterzeichnende bestätigt den Erhalt von 1 Schlüssel, die Kenntnisnahme und Akzeptanz sämtlicher oben aufgeführten Bedingungen."],
            'legacy-team' => ['name' => 'Materialabgabe Mannschaft', 'title' => 'Materialabgabe', 'type_code' => 'MATERIALQUITTUNG', 'signature_required' => true, 'items' => [],
                'text' => "Verantwortlicher Verein: {{Dokument.Verantwortlicher}}\n\nMit der Unterschrift bestätigt die Trainerin bzw. der Trainer den Erhalt der auf diesem Formular aufgeführten Materialien in der angegebenen Menge. Das abgegebene Material ist Vereinseigentum und ist sorgfältig zu behandeln. Defektes Material darf nicht selbst entsorgt werden und ist dem Materialverantwortlichen zurückzugeben. Das Material ist am Ende der Saison oder bei Beendigung der Trainertätigkeit zurückzugeben. Fehlendes oder beschädigtes Material ist bei der Rückgabe entsprechend zu deklarieren. Bei hohem Materialverlust behält sich der Verein vor, die entstandenen Kosten ganz oder teilweise der Trainerin bzw. dem Trainer in Rechnung zu stellen."],
        ];
    }
}
