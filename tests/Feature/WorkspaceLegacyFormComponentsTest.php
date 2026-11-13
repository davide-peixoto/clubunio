<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\BackofficeReadContext;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkspaceLegacyFormComponentsTest extends TestCase
{
    private const VIEWS = [
        'planning/form', 'planning/show', 'planning/options', 'planning/series',
        'training/create', 'training/show',
        'bus/create', 'bus/show', 'bus/edit', 'bus/assignment', 'bus/options',
    ];

    public function test_owned_templates_compile_and_keep_workspace_only_components(): void
    {
        foreach (self::VIEWS as $view) {
            $source = $this->source($view);
            $this->assertStringContainsString("request()->attributes->get('clubunioWorkspace')", $source, $view);
            $this->assertStringContainsString('@if($workspaceComponents)<x-filament::', $source, $view);
            $this->assertStringContainsString('@csrf', $source, $view);
            $this->assertStringContainsString("@include('clubunio.workspace.context')", $source, $view);
            $this->assertDoesNotMatchRegularExpression('/<x-filament::(?:button|icon-button)\b[^>]*\sclass="(?:primary|icon-button)"/', $source);
            $file = tempnam(sys_get_temp_dir(), 'clubunio-blade-');
            try {
                file_put_contents($file, Blade::compileString($source));
                $lint = new Process([PHP_BINARY, '-l', $file]);
                $lint->run();
                $this->assertTrue($lint->isSuccessful(), $view.': '.$lint->getOutput().$lint->getErrorOutput());
            } finally {
                unlink($file);
            }
        }
    }

    public function test_bus_edit_keeps_values_routes_tokens_and_portal_fallback(): void
    {
        $this->app['session']->start();
        request()->setLaravelSession($this->app['session']->driver());
        session()->flashInput(['location' => 'Changed & retained', 'reason' => 'Fixture reason']);
        $tenant = (object) ['slug' => 'fixture'];
        $row = (object) [
            'id' => 23, 'revision' => 7, 'bus_id' => 4,
            'starts_at' => '2026-11-02T10:00:00+01:00', 'ends_at' => '2026-11-02T12:00:00+01:00',
            'location' => 'Original', 'description' => 'Fixture description',
        ];
        $buses = collect([(object) ['id' => 4, 'name' => 'Fixture bus', 'active' => true]]);
        foreach ([true, false] as $workspace) {
            request()->attributes->set('clubunioWorkspace', $workspace);
            request()->attributes->set('workspaceToken', 'fixture-token');
            $html = $this->renderContent('bus/edit', compact('tenant', 'row', 'buses'));
            $xpath = $this->xpath($html);
            $this->assertSame(route('clubunio.bus.reschedule', ['fixture', 23]), $xpath->evaluate('string(//form/@action)'));
            $this->assertSame('post', $xpath->evaluate('string(//form/@method)'));
            $this->assertSame('7', $xpath->evaluate('string(//input[@name="revision"]/@value)'));
            $this->assertSame('Changed & retained', $xpath->evaluate('string(//input[@name="location"]/@value)'));
            $this->assertSame('Fixture reason', $xpath->evaluate('string(//textarea[@name="reason"])'));
            $this->assertSame('4', $xpath->evaluate('string(//select[@name="bus_id"]/option[@selected]/@value)'));
            $this->assertSame('2026-11-02T10:00', $xpath->evaluate('string(//input[@name="starts_at"]/@value)'));
            $this->assertSame(1, $xpath->query('//input[@name="_token"]')->length);
            $this->assertSame($workspace ? 1 : 0, $xpath->query('//input[@name="_workspace" and @value="fixture-token"]')->length);
            $this->assertSame($workspace, str_contains($html, 'fi-input-wrp'));
            $this->assertSame($workspace, str_contains($html, 'fi-btn'));
            $this->assertSame(1, $xpath->query('//input[@name="location"]')->length);
        }
    }

    public function test_assignment_preserves_locked_options_in_both_contexts(): void
    {
        $tenant = (object) ['slug' => 'fixture'];
        $row = (object) ['id' => 23, 'revision' => 7, 'team_season_id' => 11, 'member_id' => 8];
        $teams = collect([(object) ['id' => 11, 'label' => 'Selected'], (object) ['id' => 12, 'label' => 'Locked']]);
        $members = collect([(object) ['id' => 8, 'first_name' => 'Fixture', 'last_name' => 'Member']]);
        foreach ([true, false] as $workspace) {
            request()->attributes->set('clubunioWorkspace', $workspace);
            $xpath = $this->xpath($this->renderContent('bus/assignment', compact('tenant', 'row', 'teams', 'members')));
            $this->assertSame(1, $xpath->query('//select[@name="team_season_id"]/option[@value="11" and @selected and not(@disabled)]')->length);
            $this->assertSame(1, $xpath->query('//select[@name="team_season_id"]/option[@value="12" and @disabled]')->length);
            $this->assertSame(1, $xpath->query('//textarea[@name="reason" and @required and @minlength="3" and @maxlength="1000"]')->length);
        }
    }

    public function test_native_buttons_keep_external_form_and_status_payload(): void
    {
        preg_match('/<x-filament::icon-button\s+form-id="member-[\s\S]*?\/>/', $this->source('planning/show'), $match);
        $this->assertNotEmpty($match);
        $xpath = $this->xpath(Blade::render($match[0], ['member' => (object) ['id' => 42]]));
        $this->assertSame(1, $xpath->query('//button[@form="member-42" and @type="submit" and @aria-label="Teilnahme speichern"]')->length);
        preg_match_all('/<x-filament::button\b[^>]*\bname="status"[\s\S]*?<\/x-filament::button>/', $this->source('bus/show'), $matches);
        $this->assertCount(3, $matches[0]);
        $xpath = $this->xpath(Blade::render(implode('', $matches[0])));
        foreach (['GENEHMIGT', 'ABGELEHNT', 'STORNIERT'] as $status) {
            $this->assertSame(1, $xpath->query('//button[@name="status" and @value="'.$status.'" and @type="submit"]')->length);
        }
    }

    public function test_training_generation_remains_disabled_without_a_team(): void
    {
        preg_match('/<x-filament::button\b[^\n]*?:disabled="\$teams->isEmpty\(\)"[\s\S]*?<\/x-filament::button>/', $this->source('training/create'), $match);
        $this->assertNotEmpty($match);
        foreach ([collect(), collect([1])] as $teams) {
            $xpath = $this->xpath(Blade::render($match[0], compact('teams')));
            $this->assertSame(1, $xpath->query('//button[@type="submit"]')->length);
            $this->assertSame($teams->isEmpty() ? 1 : 0, $xpath->query('//button[@disabled]')->length);
        }
    }

    public function test_calendar_keeps_the_same_filters_and_only_uses_filament_in_backoffice(): void
    {
        $date = \Carbon\CarbonImmutable::parse('2026-11-13', 'Europe/Zurich');
        $data = [
            'tenant' => (new Tenant)->forceFill(['id' => 1, 'slug' => 'fixture']),
            'actor' => new Account,
            'filters' => ['view' => 'week', 'date' => $date->toDateString(), 'buses' => 1],
            'seasons' => [], 'teams' => collect(), 'managed' => [], 'events' => collect(),
            'previous' => $date->subWeek(), 'next' => $date->addWeek(), 'anchor' => $date,
            'start' => $date, 'end' => $date->addWeek(), 'days' => [], 'busReservations' => collect(),
        ];
        $this->mock(BackofficeReadContext::class)->shouldReceive('tenantsFor')->andReturn(collect([$data['tenant']]));
        foreach ([true, false] as $workspace) {
            request()->attributes->set('clubunioWorkspace', $workspace);
            $html = $this->renderContent('planning/calendar', $data);
            $xpath = $this->xpath($html);
            $this->assertSame($workspace, str_contains($html, 'workspace-calendar-views'));
            $this->assertSame(1, $xpath->query('//input[@name="buses" and @checked and @value="1"]')->length);
            $this->assertSame('2026-11-13', $xpath->evaluate('string(//input[@name="date"]/@value)'));
            $this->assertSame(1, $xpath->query('//a[@aria-current="page" and contains(@href, "view=week")]')->length);
        }
    }

    public function test_bexio_connection_keeps_workspace_token_and_configuration_guard(): void
    {
        request()->attributes->set('clubunioWorkspace', true);
        request()->attributes->set('workspaceToken', 'fixture-token');
        foreach ([true, false] as $ready) {
            $source = preg_replace('/@extends\([^\n]+\)\r?\n/', '', $this->source('bexio/connection'));
            $html = Blade::render(str_replace(["@section('native-content')", '@endsection'], '', $source), [
                'tenant' => (object) ['slug' => 'fixture'], 'connected' => false, 'ready' => $ready,
                'callback' => 'https://example.invalid/bexio/callback',
            ]);
            $xpath = $this->xpath($html);
            $this->assertSame(route('clubunio.bexio.start'), $xpath->evaluate('string(//form/@action)'));
            $this->assertSame(1, $xpath->query('//input[@name="_workspace" and @value="fixture-token"]')->length);
            $this->assertSame($ready ? 0 : 1, $xpath->query('//button[@type="submit" and @disabled]')->length);
            $this->assertStringContainsString('fi-badge', $html);
        }
    }

    public function test_support_uses_admin_layout_without_changing_sensitive_form_fields(): void
    {
        $source = $this->source('support/index');
        $this->assertStringContainsString('<x-filament-panels::layout>', $source);
        $source = str_replace(['<x-filament-panels::layout>', '</x-filament-panels::layout>'], '', $source);
        $accounts = collect([
            (object) ['role' => 'admin', 'account_id' => 1, 'account' => (object) ['display_name' => 'Synthetic admin']],
            (object) ['role' => 'office', 'account_id' => 2, 'account' => (object) [
                'display_name' => 'Synthetic office', 'username' => 'office@example.invalid', 'active' => true, 'api_only' => false,
            ]],
        ]);
        $html = Blade::render($source, [
            'allowed' => true, 'accounts' => $accounts, 'grants' => collect(), 'events' => [],
            'errors' => new \Illuminate\Support\ViewErrorBag,
        ]);
        $xpath = $this->xpath($html);
        $this->assertSame(2, $xpath->query('//form[@method="post"]/input[@name="_token"]')->length);
        $this->assertSame(2, $xpath->query('//input[@name="password" and @type="password" and @required and not(@value)]')->length);
        $this->assertSame(2, $xpath->query('//textarea[@name="reason" and @required and @minlength="10" and @maxlength="500"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="target_id"]/option[@value="2"]')->length);
        $this->assertSame(0, $xpath->query('//select[@name="target_id"]/option[@value="1"]')->length);
        $routes = app('router')->getRoutes();
        $setup = \Filament\Http\Middleware\SetUpPanel::class.':clubunio';
        $this->assertContains($setup, $routes->getByName('clubunio.support.index')->gatherMiddleware());
        foreach (['start', 'grant', 'stop'] as $action) {
            $this->assertNotContains($setup, $routes->getByName('clubunio.support.'.$action)->gatherMiddleware());
        }
    }

    private function source(string $view): string
    {
        return file_get_contents(resource_path('views/clubunio/'.$view.'.blade.php'));
    }

    private function renderContent(string $view, array $data): string
    {
        // Exercise the real form without loading the authenticated layout or database.
        $source = preg_replace('/@extends\([^\n]+\)\r?\n/', '', $this->source($view));

        return Blade::render(str_replace(["@section('content')", '@endsection'], '', $source), $data);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
