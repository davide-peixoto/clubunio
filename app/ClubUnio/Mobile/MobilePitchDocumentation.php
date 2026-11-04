<?php

namespace App\ClubUnio\Mobile;

final class MobilePitchDocumentation
{
    public function specification(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $text = ['type' => 'string'];
        $date = $text + ['format' => 'date'];
        $time = $text + ['pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'];
        $revision = $text + ['maxLength' => 200, 'description' => 'Opaque source revision; echo unchanged.'];
        $nullableId = $id + ['nullable' => true];
        $placement = ['field_id' => $id, 'area_id' => $nullableId, 'date' => $date, 'starts_at' => $time,
            'duration_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440]];
        $schemas = [
            'Capabilities' => $this->object(array_fill_keys(['view', 'request', 'manage', 'approve'], ['type' => 'boolean'])),
            'Area' => $this->object(['id' => $id, 'field_id' => $id, 'name' => $text, 'name_de' => $text, 'name_en' => $text,
                'revision' => $text] + array_fill_keys(['x', 'y', 'width', 'height'], ['type' => 'number', 'minimum' => 0, 'maximum' => 1])),
            'Field' => $this->object(['id' => $id, 'name' => $text, 'available' => ['type' => 'boolean'], 'areas' => $this->listOf('Area')]),
            'Team' => $this->object(['id' => $id, 'name' => $text]),
            'Conflict' => $this->object(['type' => $text + ['enum' => ['occupancy', 'field_unavailable', 'block']],
                'key' => $text + ['nullable' => true]]),
            'Entry' => $this->object(['key' => $text, 'source' => $text + ['enum' => ['training', 'competition', 'request', 'block']],
                'source_id' => $id, 'original_date' => $date + ['nullable' => true], 'date' => $date,
                'starts_at' => $time, 'ends_at' => $time, 'starts_at_iso' => $text + ['format' => 'date-time'],
                'ends_at_iso' => $text + ['format' => 'date-time'], 'duration_minutes' => ['type' => 'integer', 'minimum' => 1],
                'field_id' => $id, 'area_id' => $nullableId, 'team_season_ids' => ['type' => 'array', 'items' => $id],
                'title' => $text, 'status' => $text + ['enum' => ['approved', 'pending', 'rejected', 'cancelled']],
                'revision' => $revision, 'editable' => ['type' => 'boolean'], 'conflicts' => $this->listOf('Conflict')]),
            'Calendar' => $this->object(['from' => $date, 'to' => $date, 'timezone' => $text + ['enum' => ['Europe/Zurich']],
                'fields' => $this->listOf('Field'), 'entries' => $this->listOf('Entry'), 'capabilities' => $this->ref('Capabilities')]),
            'Options' => $this->object(['fields' => $this->listOf('Field'), 'teams' => $this->listOf('Team'), 'capabilities' => $this->ref('Capabilities')]),
            'FriendlyRequest' => $this->object($placement + ['team_season_id' => $id, 'opponent' => $text + ['maxLength' => 255],
                'notes' => $text + ['nullable' => true, 'maxLength' => 2000]], ['field_id', 'date', 'starts_at', 'team_season_id', 'opponent']),
            'Move' => $this->object($placement + ['key' => $text + ['maxLength' => 160], 'revision' => $revision],
                ['field_id', 'date', 'starts_at', 'duration_minutes', 'key', 'revision']),
            'Review' => $this->object(['revision' => $revision, 'decision' => $text + ['enum' => ['approved', 'rejected']]]),
            'CompetitionTeams' => $this->object(['revision' => $revision, 'team_season_ids' => [
                'type' => 'array', 'maxItems' => 20, 'uniqueItems' => true, 'items' => $id,
                'description' => 'Replaces the current stored mapping. An empty list clears all current assignments, including previously SFV-derived assignments. No separate provenance is retained. A later actually changed and reviewed SFV import regenerates authoritative numeric mappings.',
            ]]),
            'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => [
                'type' => 'object', 'required' => ['code', 'message', 'request_id'], 'properties' => [
                    'code' => $text, 'message' => $text, 'request_id' => $text + ['format' => 'uuid'],
                    'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => $text]],
                ],
            ]]],
        ];
        $schemas['FriendlyRequest']['properties']['duration_minutes']['default'] = 120;
        $schemas['Move']['properties']['duration_minutes']['maximum'] = 44640;
        $schemas['Move']['properties']['duration_minutes']['description'] = 'Up to 44640 for blocks; the core limits other sources to 1440.';
        $schemas['Entry']['properties']['requester_account_id'] = $id + ['description' => 'Only present for an authorized manager or the requester.'];
        $root = '/clubs/{tenant}';
        $paths = [];
        foreach ([
            ['/pitch-planning/options', 'get', 'options', 'Options', null],
            ['/pitch-planning/calendar', 'get', 'calendar', 'Calendar', null],
            ['/pitch-planning/requests', 'post', 'request', 'Entry', 'FriendlyRequest'],
            ['/pitch-planning/move', 'post', 'move', 'Entry', 'Move'],
            ['/pitch-planning/requests/{booking}/review', 'post', 'review', 'Entry', 'Review'],
            ['/pitch-planning/competitions/{competition}/teams', 'post', 'competition_teams', 'Entry', 'CompetitionTeams'],
            ['/my-teams/{team}/pitch-calendar', 'get', 'team_calendar', 'Calendar', null],
        ] as [$path, $method, $name, $result, $body]) {
            $parameters = [['name' => 'tenant', 'in' => 'path', 'required' => true, 'schema' => $id]];
            foreach (['booking', 'team', 'competition'] as $parameter) {
                if (str_contains($path, '{'.$parameter.'}')) $parameters[] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => $id];
            }
            if ($result === 'Calendar') {
                foreach (['from', 'to'] as $parameter) $parameters[] = ['name' => $parameter, 'in' => 'query', 'required' => true, 'schema' => $date];
                $parameters[] = ['name' => 'period_id', 'in' => 'query', 'schema' => $id];
                if ($name === 'calendar') $parameters[] = ['name' => 'team_season_id', 'in' => 'query', 'schema' => $id];
            }
            $responses = ['200' => ['description' => 'Saved entry or authorized projection.', 'content' => [
                'application/json' => ['schema' => $this->object(['data' => $this->ref($result)])],
            ]]];
            foreach ([401, 403, 404, 409, 422, 429, 503] as $status) {
                $responses[(string) $status] = ['description' => match ($status) {
                    401 => 'Personal session missing, expired or revoked.', 403 => 'Current personal/domain permission denied.',
                    404 => 'Unknown or tenant-scoped record.', 409 => 'Stale revision or occupancy conflict. Reload; do not blindly retry.',
                    422 => 'Invalid or unknown input fields.', 429 => 'Rate limited.', 503 => 'Feature or schema unavailable.',
                }, 'content' => ['application/json' => ['schema' => $this->ref('Error')]]];
            }
            $operation = ['operationId' => 'mobile_pitch_'.$name, 'tags' => ['Personal pitch planning'],
                'summary' => str_replace('_', ' ', ucfirst($name)), 'parameters' => $parameters, 'responses' => $responses,
                'description' => 'Personal mobile session only. Current core permissions apply; capabilities are presentation hints, not authority. '.match ($name) {
                    'request' => 'Trainer/TL request only their assigned active teams. Pending, never auto-approved. Only this action can enqueue new-request email to admin/office.',
                    'review' => 'Admin/office only; trainers and technical leads cannot approve. Approval rechecks occupancy.',
                    'competition_teams' => 'Admin/office with current competition-update permission only. Echo the opaque revision. Team-season mappings replace current assignments and are tenant-validated by configureCompetition; no name inference, permission grant or modification of SFV source records.',
                    'move' => 'Admin/office only. Training changes one dated occurrence, not its weekly base. Successful moves enqueue staff in-app/push, never email.',
                    'calendar', 'team_calendar' => 'Inclusive civil dates, maximum 42 days. Europe/Zurich date and HH:mm fields stay unconverted; ISO timestamps identify absolute instants. Team calendar first checks existing team access. Pending requests do not reserve space.',
                    default => 'Requestable teams and explicit view/request/manage/approve capabilities from the shared service.',
                }];
            if ($body) $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $this->ref($body)]]];
            $paths[$root.$path][$method] = $operation;
        }
        return ['openapi' => '3.0.3', 'info' => ['title' => 'ClubUnio Personal Pitch Planning API', 'version' => '1.0.0',
            'description' => 'Development contract, disabled until explicit release. No automatic idempotency replay for personal writes. Reload after uncertain outcomes. Weekly training DTOs stay unchanged.'],
            'servers' => [['url' => '/api/mobile/v1']], 'security' => [['personalBearer' => []]], 'paths' => $paths,
            'components' => ['securitySchemes' => ['personalBearer' => ['type' => 'http', 'scheme' => 'bearer',
                'description' => 'Personal mobile session token. Not an integration token, web cookie or administrator impersonation.']], 'schemas' => $schemas]];
    }

    private function ref(string $name): array { return ['$ref' => '#/components/schemas/'.$name]; }
    private function listOf(string $name): array { return ['type' => 'array', 'items' => $this->ref($name)]; }
    private function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required ?? array_keys($properties)];
    }
}
