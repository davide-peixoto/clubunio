<?php

namespace Tests\Feature;

use App\ClubUnio\Resources\AccountMemberLinkResource;
use App\ClubUnio\Resources\Relations\ActiveRecordActions;
use Illuminate\Translation\Translator;
use Tests\TestCase;

class DialogTranslationsTest extends TestCase
{
    private function useDialogLanguage(string $locale): void
    {
        $english = json_decode(file_get_contents(base_path('lang/en.json')), true, 512, JSON_THROW_ON_ERROR);
        $dialogs = json_decode(file_get_contents(base_path('lang/parts/dialogs.en.json')), true, 512, JSON_THROW_ON_ERROR);
        $english = array_replace($english, $dialogs);
        $translator = new Translator(app('translation.loader'), $locale);
        $translator->setLoaded(['*' => ['*' => [
            'en' => $english,
            'de' => array_combine(array_keys($english), array_keys($english)),
        ]]]);
        $translator->setFallback('de');
        $this->app->instance('translator', $translator);
    }

    public function test_dialog_catalog_preserves_all_placeholders(): void
    {
        $catalog = json_decode(file_get_contents(base_path('lang/parts/dialogs.en.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($catalog);
        foreach ($catalog as $key => $value) {
            $this->assertIsString($value, $key);
            $this->assertNotSame('', trim($value), $key);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
        }
    }

    public function test_dialog_actions_translate_without_changing_action_ids(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->useDialogLanguage($locale);
            [$deactivate, $restore] = ActiveRecordActions::make(fn () => null);
            $this->assertSame('deactivateRecord', $deactivate->getName());
            $this->assertSame('restoreRecord', $restore->getName());
            $this->assertSame($locale === 'en' ? 'Deactivate' : 'Deaktivieren', $deactivate->getTooltip());
            $this->assertSame($locale === 'en' ? 'Restore' : 'Wiederherstellen', $restore->getTooltip());
            $this->assertSame($locale === 'en' ? 'The entry and its history are retained.' : 'Der Eintrag und seine Historie bleiben erhalten.', $restore->getModalDescription());
        }
    }

    public function test_placeholders_preserve_dynamic_values_in_both_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->useDialogLanguage($locale);
            $this->assertSame($locale === 'en' ? 'Invoice #731' : 'Rechnung #731', __('Rechnung #:id', ['id' => 731]));
            $this->assertSame($locale === 'en' ? 'Published internally: 12 recipients' : 'Intern veröffentlicht: 12 Empfänger', __('Intern veröffentlicht: :count Empfänger', ['count' => 12]));
            $reference = 'Prüfung / customer-entered :name';
            $this->assertStringEndsWith($reference, __('Diagnose: :reference', ['reference' => $reference]));
        }
    }

    public function test_option_translation_preserves_structural_keys_and_constants(): void
    {
        $this->useDialogLanguage('en');
        $states = AccountMemberLinkResource::STATES;
        $fields = AccountMemberLinkResource::fields(false);
        $translated = $fields[3]->getOptions();
        $this->assertSame(['pending', 'approved', 'revoked'], array_keys($translated));
        $this->assertSame('To review', $translated['pending']);
        $this->assertSame(['self' => 'Own membership', 'parent' => 'Parent representation'], $fields[2]->getOptions());
        $this->assertSame($states, AccountMemberLinkResource::STATES);
        $this->assertSame('Zu prüfen', AccountMemberLinkResource::STATES['pending']);
    }

    public function test_explicit_dialog_literals_are_translated_and_sources_parse(): void
    {
        $methods = ['title', 'body', 'tooltip', 'helperText', 'placeholder', 'modalHeading', 'modalDescription', 'modalSubmitActionLabel', 'modalCancelActionLabel', 'emptyStateHeading', 'emptyStateDescription', 'addActionLabel'];
        $checked = 0;
        foreach (['app/ClubUnio/Resources', 'app/ClubUnio/Pages', 'app/Livewire'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder))) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), 'MessageTemplateResource')) {
                    continue;
                }
                $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
                foreach ($tokens as $i => $token) {
                    if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $methods, true) || ($tokens[$i + 1] ?? null) !== '(') {
                        continue;
                    }
                    $literal = $tokens[$i + 2] ?? null;
                    if (is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $this->assertDoesNotMatchRegularExpression('/[a-zA-Z]/', $literal[1], $file->getPathname().':'.$literal[2]);
                    }
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function test_setting_badge_colours_and_tab_labels_are_language_independent(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->useDialogLanguage($locale);
            $livewire = \Mockery::mock(\Filament\Tables\Contracts\HasTable::class);
            $table = \App\ClubUnio\Resources\SettingResource::table(\Filament\Tables\Table::make($livewire));
            $column = $table->getColumn('usage');
            foreach ([
                ['general', 'SaisonID', false, 'success'],
                ['integrations', 'EXAMPLE', true, 'success'],
                ['integrations', 'EXAMPLE', false, 'gray'],
                ['general', 'SommerWinter', true, 'gray'],
            ] as [$area, $key, $enabled, $color]) {
                $record = (new \App\ClubUnio\Models\Setting)->forceFill(compact('area', 'key', 'enabled'));
                $column->record($record);
                $state = app(\App\ClubUnio\Services\SettingOptions::class)->usage($record);
                $this->assertSame($color, $column->getColor($state));
            }
            $this->assertSame($locale === 'en' ? 'All' : 'Alle', \Filament\Schemas\Components\Tabs\Tab::make('Alle')->getLabel());
        }
    }

    public function test_validation_catalogs_cover_installed_rules_and_preserve_placeholders(): void
    {
        $vendor = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $english = require lang_path('en/validation.php');
        $german = require lang_path('de/validation.php');
        $this->assertSame(array_keys($english['attributes']), array_keys($german['attributes']));
        unset($vendor['custom'], $vendor['attributes'], $english['custom'], $english['attributes'], $german['custom'], $german['attributes']);
        $vendor = \Illuminate\Support\Arr::dot($vendor);
        $english = \Illuminate\Support\Arr::dot($english);
        $german = \Illuminate\Support\Arr::dot($german);
        $this->assertSame($vendor, $english);
        $this->assertSame(array_keys($vendor), array_keys($german));
        foreach ($vendor as $key => $message) {
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $message, $expected);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $german[$key], $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
            foreach (['de', 'en'] as $locale) {
                $this->assertTrue(app('translator')->hasForLocale('validation.'.$key, $locale), $locale.':'.$key);
            }
        }
    }

    public function test_scope_presentation_translates_without_changing_api_values(): void
    {
        $service = app(\App\ClubUnio\Api\ApiTokenService::class);
        $this->useDialogLanguage('de');
        $raw = $service->scopes();
        $this->assertSame($raw, $service->scopes(translateLabels: true));
        $this->useDialogLanguage('en');
        $translated = $service->scopes(translateLabels: true);
        $this->assertSame($raw, $service->scopes());
        $this->assertSame(array_keys($raw), array_keys($translated));
        $this->assertSame('Website: Teams', $translated['website:teams:read']);
        $this->assertSame('Webseite: Mannschaften', \App\ClubUnio\Api\ApiTokenService::WEBSITE['website:teams:read'][2]);
        $this->assertSame('Members: View lists and details', $translated['members:read']);
        foreach (array_merge(\App\ClubUnio\Services\PermissionCatalog::MODULES, \App\ClubUnio\Services\PermissionCatalog::FUNCTIONS) as $label) {
            $this->assertTrue(app('translator')->hasForLocale($label, 'en'), $label);
        }
    }

    public function test_copy_suffix_does_not_translate_user_profile_name(): void
    {
        $record = (new \App\ClubUnio\Models\AccessProfile)->forceFill([
            'name' => 'Offen', 'description' => 'User-entered description', 'active' => true,
            'permissions' => ['members.view'], 'mobile_modules' => null, 'revision' => 7,
        ]);
        foreach (['de', 'en'] as $locale) {
            $this->useDialogLanguage($locale);
            $copy = \App\ClubUnio\Resources\AccessProfileResource::formValues($record, true);
            $this->assertSame($locale === 'en' ? 'Offen (Copy)' : 'Offen (Kopie)', $copy['name']);
            $this->assertSame('User-entered description', $copy['description']);
            $this->assertSame(['members' => ['view']], $copy['modules']);
            $this->assertSame(0, $copy['revision']);
            $this->assertSame('Offen', \App\ClubUnio\Resources\AccessProfileResource::formValues($record)['name']);
            $this->assertSame('Offen', $record->name);
        }
    }

    public function test_framework_auth_password_and_pagination_catalogs(): void
    {
        foreach (['auth', 'passwords', 'pagination'] as $group) {
            $vendor = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$group.'.php');
            $english = require lang_path('en/'.$group.'.php');
            $german = require lang_path('de/'.$group.'.php');
            $this->assertSame($vendor, $english);
            $this->assertSame(array_keys($english), array_keys($german));
            foreach ($english as $key => $message) {
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $message, $expected);
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $german[$key], $actual);
                $this->assertSame($expected[0], $actual[0], $group.'.'.$key);
                foreach (['de' => $german, 'en' => $english] as $locale => $catalog) {
                    $this->assertSame($catalog[$key], app('translator')->get($group.'.'.$key, [], $locale, false));
                }
            }
        }
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $paginator = new \Illuminate\Pagination\Paginator([1, 2, 3], 2, 2);
            $html = $paginator->render('pagination::simple-default');
            $this->assertStringContainsString($locale === 'de' ? 'Zurück' : 'Previous', $html);
            $this->assertStringContainsString($locale === 'de' ? 'Weiter' : 'Next', $html);
            $this->assertStringContainsString('17', __('auth.throttle', ['seconds' => 17]));
        }
    }

    public function test_real_service_validator_uses_localized_rules_and_nested_attributes(): void
    {
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $validator = \Illuminate\Support\Facades\Validator::make([
                'email' => 'invalid', 'revision' => -1, 'items' => [['quantity' => 0]],
                'starts_at' => '2026-11-04', 'ends_at' => '2026-11-03',
            ], [
                'reason' => 'required|string', 'email' => 'email', 'revision' => 'integer|min:0',
                'items.*.quantity' => 'integer|min:1', 'ends_at' => 'date|after_or_equal:starts_at',
            ]);
            $errors = $validator->errors();
            $this->assertSame($locale === 'de' ? 'Begründung ist erforderlich.' : 'The reason field is required.', $errors->first('reason'));
            $this->assertSame($locale === 'de' ? 'E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.' : 'The email address field must be a valid email address.', $errors->first('email'));
            $this->assertSame($locale === 'de' ? 'Angezeigte Version muss mindestens 0 sein.' : 'The displayed version field must be at least 0.', $errors->first('revision'));
            $this->assertSame($locale === 'de' ? 'Anzahl des Eintrags muss mindestens 1 sein.' : 'The item quantity field must be at least 1.', $errors->first('items.0.quantity'));
            $this->assertStringContainsString($locale === 'de' ? 'Beginn' : 'start', $errors->first('ends_at'));
            $this->assertStringNotContainsString('validation.', implode(' ', $errors->all()));
            $this->assertDoesNotMatchRegularExpression('/:(attribute|min|date)/', implode(' ', $errors->all()));
        }
    }

    public function test_real_english_training_form_and_confirmation_dialog_render_with_rollback_fixture(): void
    {
        // This connection is process-local RAM, never the shared Parent test database.
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        \Illuminate\Support\Facades\DB::purge('clubunio');
        $db = \Illuminate\Support\Facades\DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->statement('CREATE TABLE clubora.training_options (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, code TEXT, category TEXT, active INTEGER, sort_order INTEGER)');
        $db->statement('CREATE TABLE clubora.team_seasons (id INTEGER PRIMARY KEY, tenant_id INTEGER, label TEXT, active INTEGER)');
        $db->statement('CREATE TABLE clubora.lookup_translations (id INTEGER PRIMARY KEY, tenant_id INTEGER, source_table TEXT, source_id TEXT, source_name TEXT, locale TEXT, label TEXT, revision INTEGER)');
        $db->beginTransaction();
        try {
            $db->table('clubora.training_options')->insert([
                ['id' => 11, 'tenant_id' => 1, 'name' => 'Sommer', 'code' => 'S', 'category' => 'period', 'active' => 1, 'sort_order' => 1],
                ['id' => 12, 'tenant_id' => 1, 'name' => 'Montag', 'code' => 'MO', 'category' => 'weekday', 'active' => 1, 'sort_order' => 1],
                ['id' => 13, 'tenant_id' => 1, 'name' => 'Freier Platzname', 'code' => 'P', 'category' => 'field', 'active' => 1, 'sort_order' => 1],
            ]);
            $db->table('clubora.team_seasons')->insert(['id' => 21, 'tenant_id' => 1, 'label' => 'Offen', 'active' => 1]);
            $db->table('clubora.lookup_translations')->insert(['id' => 1, 'tenant_id' => 1, 'source_table' => 'training_options',
                'source_id' => '13', 'source_name' => 'Freier Platzname', 'locale' => 'en', 'label' => 'North pitch', 'revision' => 1]);
            \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
            \Filament\Facades\Filament::setTenant((new \App\ClubUnio\Models\Tenant)->forceFill(['id' => 1, 'name' => 'Fixture', 'slug' => 'dialog-fixture']), isQuiet: true);
            app()->setLocale('en');
            $this->useDialogLanguage('en');
            $this->app->forgetInstance(\App\ClubUnio\Services\LookupTranslations::class);
            $component = \Livewire\Livewire::test(DialogTranslationFormHarness::class)
                ->assertSuccessful()->assertSee('Summer')->assertSee('Monday')->assertSee('North pitch')
                ->assertSee('Facility side')->assertDontSee('Freier Platzname')
                ->set('data.hall', 'Offen - custom user text')
                ->assertActionVisible('deactivateRecord')->assertActionEnabled('deactivateRecord')
                ->mountAction('deactivateRecord')
                ->assertActionMounted('deactivateRecord');
            // Filament delivers mounted modal HTML separately from Livewire's full-page HTML.
            $modalHtml = implode('', $component->effects['partials'] ?? []);
            $this->assertStringContainsString('The entry and its history are retained.', $modalHtml);
            $this->assertStringContainsString('Deactivate', $modalHtml);
            $this->assertStringNotContainsString('Der Eintrag und seine Historie bleiben erhalten.', $modalHtml);
            $this->assertSame('Offen - custom user text', $component->get('data.hall'));
            $this->assertSame('Offen', $db->table('clubora.team_seasons')->value('label'));
            $this->assertSame('Freier Platzname', $db->table('clubora.training_options')->where('id', 13)->value('name'));
            $this->assertSame([13 => 'North pitch'], \App\ClubUnio\Resources\TrainingSlotResource::choices('field'));
        } finally {
            $db->rollBack();
            \Filament\Facades\Filament::setTenant(null);
        }
        $this->assertSame(0, $db->table('clubora.training_options')->count());
        $this->assertSame(0, $db->table('clubora.lookup_translations')->count());
    }
}

class DialogTranslationFormHarness extends \Livewire\Component implements \Filament\Schemas\Contracts\HasSchemas, \Filament\Actions\Contracts\HasActions
{
    use \Filament\Schemas\Concerns\InteractsWithSchemas;
    use \Filament\Actions\Concerns\InteractsWithActions;

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['team_season_id' => 21, 'period_id' => 11, 'weekday_id' => 12, 'field_id' => 13]);
    }

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\ClubUnio\Resources\TrainingSlotResource::form($schema)->statePath('data');
    }

    public function deactivateRecordAction(): \Filament\Actions\Action
    {
        return ActiveRecordActions::make(fn () => null)[0]
            ->record((new \App\ClubUnio\Models\TrainingOption)->forceFill(['id' => 13, 'active' => true, 'revision' => 1]));
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}{{ $this->deactivateRecordAction }}<x-filament-actions::modals /></div>';
    }
}
