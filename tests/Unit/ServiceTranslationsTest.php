<?php

namespace Tests\Unit;

use App\ClubUnio\Services\DocumentSeriesRenderer;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\SponsorRunMoney;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

class ServiceTranslationsTest extends TestCase
{
    private function catalog(): array
    {
        return json_decode(file_get_contents(base_path('lang/parts/services.en.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function language(string $locale): void
    {
        // Exercise the independently owned part without requiring a root catalog merge.
        $loader = new ArrayLoader;
        $catalog = $this->catalog();
        $loader->addMessages('en', '*', $catalog);
        $loader->addMessages('de', '*', array_combine(array_keys($catalog), array_keys($catalog)));
        $this->app->instance('translator', new Translator($loader, $locale));
        $this->app->setLocale($locale);
        $this->app->forgetInstance('validator');
        \Illuminate\Support\Facades\Validator::clearResolvedInstance('validator');
    }

    public function test_service_catalog_preserves_all_placeholders(): void
    {
        foreach ($this->catalog() as $key => $value) {
            $this->assertNotSame('', trim($value), $key);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
        }
    }

    public function test_money_validation_uses_the_selected_language_and_keeps_values(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->language($locale);
            $message = null;
            SponsorRunMoney::rule()('amount_chf', '0.001', function ($error) use (&$message) { $message = $error; });
            $key = 'Bitte einen CHF-Betrag zwischen 0.01 und 100000.00 mit höchstens zwei Nachkommastellen eingeben.';
            $this->assertSame($locale === 'de' ? $key : $this->catalog()[$key], $message);
            $result = SponsorRunMoney::fromWeb(['amount_chf' => '12,34', 'cap_chf' => '20', 'note' => 'Nicht ersetzen']);
            $this->assertSame(1234, $result['amount_cents']);
            $this->assertSame(2000, $result['cap_cents']);
            $this->assertSame('Nicht ersetzen', $result['note']);
        }
    }

    public function test_document_placeholder_error_is_localized_without_translating_the_identifier(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->language($locale);
            try {
                (new DocumentSeriesRenderer)->substitute('{{Unbekannt.Vorname}}', []);
                $this->fail('Missing validation exception');
            } catch (ValidationException $error) {
                $this->assertSame([$locale === 'de'
                    ? 'Unbekannter oder fehlender Platzhalter: Unbekannt.Vorname'
                    : 'Unknown or missing placeholder: Unbekannt.Vorname'], $error->errors()['template']);
            }
            $this->assertSame('Name {{unverändert}}', (new DocumentSeriesRenderer)->substitute('{{Mitglied.Vorname}}', ['Mitglied.Vorname' => 'Name {{unverändert}}']));
        }
    }

    public function test_invoice_amount_error_is_localized_without_changing_the_error_key(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->language($locale);
            try {
                (new InvoiceWorkflowService)->cents('invalid');
                $this->fail('Missing validation exception');
            } catch (ValidationException $error) {
                $this->assertSame(['gross_amount' => [$locale === 'de' ? 'Ungültiger Betrag.' : 'Invalid amount.']], $error->errors());
            }
        }
    }

    public function test_direct_validation_and_abort_messages_have_no_untranslated_literals(): void
    {
        $excluded = ['MessageTemplateService', 'MessageEventCatalog', 'RegistrationMessageService', 'ExpenseMessageService', 'TestOutboxDispatcher', 'StatusAppearance', 'LookupTranslations'];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $check = function (?Node $node, string $location) use (&$check): void {
            if (!$node) { return; }
            if ($node instanceof Node\Expr\Array_) {
                foreach ($node->items as $item) { if ($item) { $check($item->value, $location); } }
            } elseif ($node instanceof Node\Expr\Ternary) {
                $check($node->if, $location);
                $check($node->else, $location);
            } else {
                $this->assertFalse($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\InterpolatedString || $node instanceof Node\Expr\BinaryOp\Concat, $location);
            }
        };
        foreach (glob(app_path('ClubUnio/Services/*.php')) as $file) {
            if (in_array(basename($file, '.php'), $excluded, true)) { continue; }
            foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file)), Node\Expr::class) as $node) {
                $location = basename($file).':'.$node->getStartLine();
                if ($node instanceof Node\Expr\FuncCall) {
                    $name = $node->name instanceof Node\Name ? $node->name->toString() : ($node->name instanceof Node\Expr\Variable ? '$'.$node->name->name : '');
                    $index = match ($name) { 'abort' => 1, 'abort_if', 'abort_unless' => 2, '$fail' => 0, default => null };
                    if ($index !== null) { $check($node->args[$index]->value ?? null, $location); }
                }
                if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && $node->name->name === 'withMessages') {
                    $check($node->args[0]->value ?? null, $location);
                }
                if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && in_array($node->name->name, ['fail', 'invalid', 'error'], true)
                    && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                    $check($node->args[0]->value ?? null, $location);
                }
            }
        }
    }

    public function test_english_csv_exports_translate_system_text_and_preserve_formula_protection(): void
    {
        $this->language('en');
        $lookup = new \App\ClubUnio\Services\LookupTranslations;
        (new \ReflectionProperty($lookup, 'available'))->setValue($lookup, false);
        $this->app->instance(\App\ClubUnio\Services\LookupTranslations::class, $lookup);
        config(['clubunio-lookups.en.Aktiv' => 'Active']);
        $actor = new \App\ClubUnio\Models\Account;
        $actor->forceFill(['id' => 1]);
        $tenant = new \App\ClubUnio\Models\Tenant;
        $tenant->forceFill(['id' => 7]);
        $permission = \Mockery::mock();
        $permission->shouldReceive('authorize')->with($actor, $tenant, \Mockery::any(), 'export');
        $this->app->instance(\App\ClubUnio\Services\ModulePermissionService::class, $permission);
        $dashboard = \Mockery::mock();
        $dashboard->shouldReceive('scopeOpenInvoices')->once();
        $this->app->instance(\App\ClubUnio\Services\DashboardService::class, $dashboard);
        $audit = \Mockery::mock();
        $audit->shouldReceive('table')->with('clubunio.object_changes')->andReturnSelf();
        $audit->shouldReceive('insert')->times(3)->andReturn(true);
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->with('clubunio')->andReturn($audit);

        $member = new \App\ClubUnio\Models\Member;
        $member->forceFill(['id' => 3, 'first_name' => '=SUM(1,2)', 'last_name' => 'Aktiv']);
        $status = new \App\ClubUnio\Models\MemberStatus;
        $status->forceFill(['id' => 5, 'tenant_id' => 7, 'name' => 'Aktiv', 'code' => 'A']);
        $member->setRelation('status', $status);
        $key = new \App\ClubUnio\Models\MemberKey;
        $key->forceFill(['number' => 9, 'name' => '=SUM(1,2)', 'color' => 'Aktiv', 'active' => true]);
        $key->setRelation('member', $member);
        $csv = $this->csv((new \App\ClubUnio\Services\ReportExportService)->download($actor, $tenant, 'keys', $this->exportQuery($key)));
        $this->assertSame(['Key number', 'Label', 'Colour', 'Member no.', 'Member', 'Member status', 'Active'], $csv[0]);
        $this->assertSame("'=SUM(1,2)", $csv[1][1]);
        $this->assertSame('Aktiv', $csv[1][2]);
        $this->assertSame('Active', $csv[1][5]);
        $this->assertSame('Yes', $csv[1][6]);
        $this->assertSame('Aktiv', $status->name);
        $this->assertSame('A', $status->code);

        $recipient = new \App\ClubUnio\Models\SpotsRecipient;
        $recipient->forceFill(['kind' => 'family', 'company' => '+Formula', 'name' => 'Familie', 'street' => 'Strasse', 'postal_code' => '4410', 'city' => 'Ort', 'country' => 'Land']);
        $csv = $this->csv((new \App\ClubUnio\Services\ReportExportService)->download($actor, $tenant, 'spots', $this->exportQuery($recipient)));
        $this->assertSame(['Recipient', 'Company', 'Name', 'Street', 'Postal code', 'Town', 'Country'], $csv[0]);
        $this->assertSame(['Family', "'+Formula", 'Familie', 'Strasse', '4410', 'Ort', 'Land'], $csv[1]);

        $invoice = new class extends \App\ClubUnio\Models\Invoice {
            public function getOpenAmountAttribute(): string { return '12.34'; }
        };
        $invoice->forceFill(['id' => 23, 'bexio_invoice_id' => '@provider-id']);
        $invoiceStatus = new \App\ClubUnio\Models\InvoiceStatus;
        $invoiceStatus->forceFill(['id' => 6, 'tenant_id' => 7, 'name' => 'Aktiv', 'code' => '7']);
        $invoice->setRelation('status', $invoiceStatus)->setRelation('member', $member)->setRelation('season', null);
        $csv = $this->csv((new \App\ClubUnio\Services\FinanceExportService)->download($actor, $tenant, 'invoices', $this->exportQuery($invoice)));
        $this->assertSame(['Invoice', 'Member', 'Season', 'Status', 'Outstanding CHF', 'Bexio'], $csv[0]);
        $this->assertSame('Active', $csv[1][3]);
        $this->assertSame('12.34', $csv[1][4]);
        $this->assertSame("'@provider-id", $csv[1][5]);
    }

    private function exportQuery(\Illuminate\Database\Eloquent\Model $row): \Illuminate\Database\Eloquent\Builder
    {
        $rows = new class([$row]) extends \Illuminate\Database\Eloquent\Collection {
            public function load($relations) { return $this; }
        };
        $query = \Mockery::mock(\Illuminate\Database\Eloquent\Builder::class);
        $query->shouldReceive('getModel')->andReturn($row);
        $query->shouldReceive('withoutEagerLoads', 'where', 'whereHas', 'limit')->andReturnSelf();
        $query->shouldReceive('get')->andReturn($rows);

        return $query;
    }

    private function csv(\Symfony\Component\HttpFoundation\StreamedResponse $response): array
    {
        ob_start();
        try { $response->sendContent(); $contents = ob_get_contents(); }
        finally { ob_end_clean(); }
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);

        return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), explode("\n", rtrim(substr($contents, 3), "\r\n")));
    }
}
