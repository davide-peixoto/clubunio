<?php

namespace App\ClubUnio\Mobile;

final class MobileClubhouseDocumentation
{
    public function specification(): array
    {
        $text = ['type' => 'string'];
        $date = $text + ['format' => 'date'];
        $id = ['type' => 'integer', 'minimum' => 1];
        $revision = $id + ['description' => 'Echo the latest revision. Stale writes return 422.'];
        $slots = ['type' => 'array', 'maxItems' => 8, 'items' => $this->object([
            'start' => $text + ['pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
            'end' => $text + ['pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'], 'overnight' => ['type' => 'boolean'],
        ], ['start', 'end', 'overnight'])];
        $opening = $this->object(['title' => $text + ['maxLength' => 160], 'starts_on' => $date,
            'ends_on' => $date + ['nullable' => true], 'weekdays' => ['type' => 'array', 'maxItems' => 7, 'uniqueItems' => true,
                'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7], 'description' => 'ISO weekdays; empty means a one-off opening.'],
            'intervals' => $slots, 'active' => ['type' => 'boolean'], 'revision' => $revision,
            'scope' => $text + ['enum' => ['all', 'this', 'future']], 'on' => $date], ['title', 'starts_on', 'weekdays', 'intervals', 'active']);
        $menu = $this->object(['title_de' => $text + ['maxLength' => 160], 'title_en' => $text + ['nullable' => true, 'maxLength' => 160],
            'body_de' => $text + ['maxLength' => 20000, 'nullable' => true], 'body_en' => $text + ['maxLength' => 20000, 'nullable' => true],
            'valid_from' => $date, 'valid_until' => $date, 'channels' => ['type' => 'array', 'uniqueItems' => true, 'maxItems' => 2,
                'items' => $text + ['enum' => ['web', 'app']]], 'revision' => $revision], ['title_de', 'valid_from', 'valid_until', 'channels']);
        $schemas = ['OpeningInput' => $opening, 'MenuInput' => $menu, 'Revision' => $this->object(['revision' => $revision], ['revision']),
            'ExceptionInput' => $this->object(['date' => $date, 'closed' => ['type' => 'boolean'], 'intervals' => $slots,
                'note' => $text + ['nullable' => true, 'maxLength' => 500], 'revision' => ['type' => 'integer', 'minimum' => 0]], ['date', 'closed', 'intervals', 'revision']),
            'Capabilities' => $this->object(array_fill_keys(['manage', 'hours_write', 'menus_write', 'menus_publish', 'admin'], ['type' => 'boolean'])),
            'PublicMenu' => $this->object(['id' => $id, 'title' => $text, 'body' => $text, 'has_document' => ['type' => 'boolean'],
                'valid_from' => $date, 'valid_until' => $date, 'language_fallback' => ['type' => 'boolean']]),
            'Overview' => $this->object(['timezone' => $text, 'as_of' => $text + ['format' => 'date-time'], 'date' => $date,
                'open_now' => ['type' => 'boolean'], 'open_until' => $text + ['format' => 'date-time', 'nullable' => true],
                'next_opening' => $text + ['format' => 'date-time', 'nullable' => true], 'next_opening_search_until' => $date,
                'days' => ['type' => 'array', 'items' => $this->object(['date' => $date, 'exception' => ['type' => 'boolean'],
                    'intervals' => ['type' => 'array', 'items' => $this->object(['start' => $text + ['format' => 'date-time'], 'end' => $text + ['format' => 'date-time']])]])],
                'menus' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PublicMenu']], 'capabilities' => ['$ref' => '#/components/schemas/Capabilities']]),
        ];
        $prefix = '/api/mobile/v1/clubs/{tenant}/clubhouse';
        $paths = [];
        $definitions = [
            ['', 'get', 'Published app content, current opening status and personal capabilities', null, 'Overview'],
            ['/manage', 'get', 'Working copies, opening rules, exceptions and lookup statuses; requires clubhouse view rights', null, null],
            ['/openings', 'post', 'Create a one-off or weekly opening; requires hours_write', 'OpeningInput', null],
            ['/openings/{opening}', 'patch', 'Edit all, one or future occurrences; revision required', 'OpeningInput', null],
            ['/exceptions', 'post', 'Replace one entire day; revision 0 creates, existing revision updates', 'ExceptionInput', null],
            ['/exceptions/{exception}', 'delete', 'Revert an exception with an audit record', 'Revision', null],
            ['/menus', 'post', 'Create a draft without publication; requires menus_write', 'MenuInput', null],
            ['/menus/{menu}', 'patch', 'Edit working copy; the previous published snapshot remains unchanged', 'MenuInput', null],
            ['/menus/{menu}/pdf', 'get', 'Read active published app PDF, or authorized draft with draft=1', null, 'pdf'],
            ['/menus/{menu}/pdf', 'post', 'Upload an immutable PDF to tenant storage; does not publish it', 'upload', null],
            ['/menus/{menu}/pdf', 'delete', 'Detach PDF from working copy, retaining any published version', 'Revision', null],
            ['/menus/{menu}/{operation}', 'post', 'Publish / withdraw (menus_publish), archive / restore (menus_write). Archiving live content also requires publishing rights.', 'Revision', null],
        ];
        foreach ($definitions as [$suffix, $method, $summary, $input, $output]) {
            $operation = ['tags' => ['Clubhouse'], 'summary' => $summary, 'security' => [['PersonalMobileSession' => []]],
                'parameters' => [['name' => 'tenant', 'in' => 'path', 'required' => true, 'schema' => $id]],
                'responses' => ['200' => ['description' => 'Successful result', 'content' => [$output === 'pdf' ? 'application/pdf' : 'application/json' => ['schema' => $output === 'pdf' ? $text + ['format' => 'binary'] : $this->object(['data' => $output ? ['$ref' => '#/components/schemas/'.$output] : ['type' => 'object']])]]],
                    '401' => ['description' => 'No valid personal mobile session'], '403' => ['description' => 'No current authorization'],
                    '404' => ['description' => 'Unknown, unpublished or foreign-tenant record'], '422' => ['description' => 'Invalid fields or stale revision'], '503' => ['description' => 'Storage unavailable']]];
            foreach (['opening', 'exception', 'menu', 'operation'] as $parameter) {
                if (str_contains($suffix, '{'.$parameter.'}')) {
                    $operation['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true,
                        'schema' => $parameter === 'operation' ? $text + ['enum' => ['publish', 'withdraw', 'archive', 'restore']] : $id];
                }
            }
            if ($output === 'pdf') {
                $operation['parameters'][] = ['name' => 'draft', 'in' => 'query', 'schema' => $text + ['enum' => ['0', '1']]];
            }
            if ($suffix === '/manage') {
                $operation['parameters'][] = ['name' => 'page', 'in' => 'query', 'schema' => $id + ['maximum' => 10000, 'default' => 1]];
                $operation['parameters'][] = ['name' => 'status', 'in' => 'query', 'schema' => $text + ['enum' => ['all', 'draft', 'published', 'archived']]];
                $operation['parameters'][] = ['name' => 'search', 'in' => 'query', 'schema' => $text + ['maxLength' => 160]];
            }
            if ($input) {
                $schema = $input === 'upload' ? $this->object(['revision' => $revision, 'file' => $text + ['format' => 'binary', 'description' => 'PDF only; at most 10 MiB.']], ['revision', 'file']) : ['$ref' => '#/components/schemas/'.$input];
                $operation['requestBody'] = ['required' => true, 'content' => [$input === 'upload' ? 'multipart/form-data' : 'application/json' => ['schema' => $schema]]];
            }
            $paths[$prefix.$suffix][$method] = $operation;
        }
        foreach (['data' => 'application/json', 'menus/{menu}/pdf' => 'application/pdf'] as $suffix => $mime) {
            $parameters = [['name' => 'club', 'in' => 'path', 'required' => true, 'schema' => $text]];
            if (str_contains($suffix, '{menu}')) {
                $parameters[] = ['name' => 'menu', 'in' => 'path', 'required' => true, 'schema' => $id];
            }
            $paths['/public/clubhouse/{club}/'.$suffix]['get'] = ['tags' => ['Published website content'], 'summary' => 'Explicit public opt-in; currently valid web publications only', 'security' => [],
                'parameters' => $parameters, 'responses' => ['200' => ['description' => 'Published content', 'content' => [$mime => ['schema' => $mime === 'application/pdf' ? $text + ['format' => 'binary'] : $this->object(['data' => ['$ref' => '#/components/schemas/Overview']])]]],
                    '404' => ['description' => 'Public access disabled, foreign domain or unpublished content']]];
        }

        return ['openapi' => '3.0.3', 'info' => ['title' => 'ClubUnio Clubhouse API', 'version' => '1.0.0',
            'description' => 'Personal mobile sessions, never integration tokens. Additional clubhouse grants do not change base roles. Text fields are plain text; DE/EN content has an explicit available-language fallback. No automatic emails, pushes or external publication calls.'],
            'paths' => $paths, 'components' => ['schemas' => $schemas, 'securitySchemes' => ['PersonalMobileSession' => ['type' => 'http', 'scheme' => 'bearer']]]];
    }

    private function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties] + ($required ? ['required' => $required] : []);
    }
}
