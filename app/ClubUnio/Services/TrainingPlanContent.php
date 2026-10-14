<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TrainingPlanContent
{
    public function validate(array $plan, int $duration): array
    {
        $data = Validator::make($plan, [
            'title' => 'required|string|max:160', 'focus' => 'required|string|max:1000',
            'principles' => 'required|array|min:1|max:12', 'principles.*' => 'required|string|max:1000',
            'qualityChecks' => 'required|array|min:1|max:15', 'qualityChecks.*' => 'required|string|max:1000',
            'exercises' => 'required|array|min:3|max:12',
            'exercises.*.phase' => 'required|string|in:Einstieg,Hauptteil,Abschluss',
            'exercises.*.title' => 'required|string|max:160', 'exercises.*.durationMinutes' => 'required|integer|min:1|max:120',
            'exercises.*.setup' => 'required|string|max:3000', 'exercises.*.flow' => 'required|string|max:5000',
            'exercises.*.coachingPoints' => 'required|array|min:1|max:8', 'exercises.*.coachingPoints.*' => 'required|string|max:1000',
            'exercises.*.easier' => 'required|string|max:1000', 'exercises.*.harder' => 'required|string|max:1000',
            'exercises.*.diagramDescription' => 'required|string|max:1000',
            'exercises.*.markers' => 'required|array|min:1|max:50',
            'exercises.*.markers.*.kind' => 'required|in:player,cone,ball',
            'exercises.*.markers.*.x' => 'required|integer|min:0|max:100',
            'exercises.*.markers.*.y' => 'required|integer|min:0|max:100',
        ])->validate();
        // Only allow the defined textual contract; never render provider HTML or SVG.
        $data = array_intersect_key($data, array_flip(['title', 'focus', 'principles', 'qualityChecks', 'exercises']));
        $fields = ['phase', 'title', 'durationMinutes', 'setup', 'flow', 'coachingPoints', 'easier', 'harder', 'diagramDescription', 'markers'];
        $data['exercises'] = array_map(fn ($e) => array_intersect_key($e, array_flip($fields)), $data['exercises']);
        $phases = array_values(array_unique(array_column($data['exercises'], 'phase')));
        $sequence = array_map(fn ($e) => array_search($e['phase'], ['Einstieg', 'Hauptteil', 'Abschluss']), $data['exercises']);
        $ordered = $sequence;
        sort($ordered);
        if ($sequence !== $ordered || $phases !== ['Einstieg', 'Hauptteil', 'Abschluss'] || array_sum(array_column($data['exercises'], 'durationMinutes')) !== $duration) {
            throw ValidationException::withMessages(['plan' => 'Einstieg, Hauptteil und Abschluss müssen in dieser Reihenfolge vorkommen. Die Übungszeiten müssen der Gesamtdauer entsprechen.']);
        }
        $alignment = implode(' ', [...$data['principles'], ...$data['qualityChecks']]);
        if (! preg_match('/SV\s*Sissach/iu', $alignment) || ! preg_match('/\bSFV\b/iu', $alignment)) {
            throw ValidationException::withMessages(['plan' => 'Die Prüfung der SV-Sissach- und SFV-Philosophie fehlt.']);
        }

        return $data;
    }

    public function schema(): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => $string];
        $exercise = ['phase' => ['type' => 'string', 'enum' => ['Einstieg', 'Hauptteil', 'Abschluss']],
            'title' => $string, 'durationMinutes' => ['type' => 'integer'], 'setup' => $string, 'flow' => $string,
            'coachingPoints' => $strings, 'easier' => $string, 'harder' => $string, 'diagramDescription' => $string,
            'markers' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['player', 'cone', 'ball']], 'x' => ['type' => 'integer'], 'y' => ['type' => 'integer'],
            ], 'required' => ['kind', 'x', 'y'], 'additionalProperties' => false]]];
        $properties = ['title' => $string, 'focus' => $string, 'principles' => $strings, 'qualityChecks' => $strings,
            'exercises' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $exercise, 'required' => array_keys($exercise), 'additionalProperties' => false]]];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function diagram(array $exercise): string
    {
        // Coordinates only: no provider-controlled SVG, external resources, or text markup.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="390" viewBox="0 0 640 390"><rect width="640" height="390" fill="#e7f2ec"/><g stroke="#527561" fill="none" stroke-width="2"><rect x="20" y="20" width="600" height="350"/><path d="M320 20V370"/><circle cx="320" cy="195" r="48"/><rect x="20" y="120" width="70" height="150"/><rect x="550" y="120" width="70" height="150"/></g>';
        foreach ($exercise['markers'] ?? [] as $marker) {
            $x = 30 + max(0, min(100, (int) ($marker['x'] ?? 0))) * 5.8;
            $y = 30 + max(0, min(100, (int) ($marker['y'] ?? 0))) * 3.3;
            $svg .= match ($marker['kind'] ?? '') {
                'cone' => '<path d="M'.$x.' '.($y - 7).'l-7 14h14z" fill="#cb6c24"/>',
                'ball' => '<circle cx="'.$x.'" cy="'.$y.'" r="5" fill="#fff" stroke="#263b32"/>',
                default => '<circle cx="'.$x.'" cy="'.$y.'" r="8" fill="#19699b" stroke="#fff"/>',
            };
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg.'</svg>');
    }
}
