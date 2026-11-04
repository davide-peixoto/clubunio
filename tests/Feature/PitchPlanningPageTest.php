<?php

namespace Tests\Feature;

use App\ClubUnio\Pages\PitchPlanning;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PitchPlanningPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PitchPlanningHarness::$calls = [];
        PitchPlanningHarness::$failure = null;
        PitchPlanningHarness::$manage = true;
        PitchPlanningHarness::$fixtureEntries = [self::entry()];
        $part = json_decode(file_get_contents(base_path('lang/parts/pitch-web.en.json')), true, flags: JSON_THROW_ON_ERROR);
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR) + $part;
        app('translator')->setLoaded(['*' => ['*' => ['en' => $en, 'de' => array_combine(array_keys($en), array_keys($en))]]]);
        app()->setLocale('de');
    }

    public static function entry(): array
    {
        return ['key' => 'training:7:2026-11-04', 'source' => 'training', 'source_id' => 7, 'original_date' => '2026-11-04',
            'date' => '2026-11-04', 'starts_at' => '18:00', 'ends_at' => '19:30',
            'starts_at_iso' => '2026-11-04T18:00:00+01:00', 'ends_at_iso' => '2026-11-04T19:30:00+01:00',
            'duration_minutes' => 90, 'field_id' => 1, 'area_id' => 11, 'team_season_ids' => [2],
            'title' => 'U18 <script>unsafe</script>', 'status' => 'approved', 'revision' => 'opaque-r1', 'editable' => true, 'conflicts' => []];
    }

    public function test_registered_page_requires_filament_authentication(): void
    {
        $this->get('/pitch-planning')->assertRedirect('/login');
    }

    public function test_page_renders_fields_translated_statuses_and_accessible_edit_buttons(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)
            ->assertSee('Pitch One')->assertSee('Pitch Two')->assertSee('Genehmigt')
            ->assertSee('Tagesplan nach Spielfeld')->assertSee('Belegung öffnen:', false)
            ->assertDontSee('<script>unsafe</script>', false);
        $component->call('editEntry', self::entry()['key'])->assertSet('editor', 'edit')
            ->assertSee('Verschiebung bestätigen')->assertSet('selected.revision', 'opaque-r1');
        $this->assertSame(['options', 'calendar', 'options', 'calendar'], array_column(PitchPlanningHarness::$calls, 0));
    }

    public function test_drag_only_proposes_and_confirmed_move_preserves_server_revision(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)
            ->call('proposeMove', self::entry()['key'], 2, '2026-11-04', '19:00')
            ->assertSet('entries.0.field_id', 1)->assertSet('form.field_id', 2)->assertSet('form.area_id', null);
        $this->assertNotContains('move', array_column(PitchPlanningHarness::$calls, 0));
        $component->call('saveEntry')->assertHasNoErrors()->assertSet('editor', null);
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'move');
        $this->assertSame(self::entry()['key'], $call[1][0]);
        $this->assertSame('opaque-r1', $call[1][1]['revision']);
        $this->assertSame(2, $call[1][1]['field_id']);
        $this->assertSame(90, $call[1][1]['duration_minutes']);
    }

    public function test_conflict_keeps_dialog_and_original_projection_without_false_success(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('editEntry', self::entry()['key']);
        PitchPlanningHarness::$failure = new HttpException(409);
        $component->set('form.starts_at', '20:00')->call('saveEntry')->assertHasErrors('save')
            ->assertSet('editor', 'edit')->assertSet('entries.0.starts_at', '18:00')
            ->assertSee('Nicht gespeichert: Belegung oder Revision');
    }

    public function test_validation_errors_remain_inside_modal(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('createEntry', 'request');
        PitchPlanningHarness::$failure = ValidationException::withMessages(['opponent' => 'Opponent required']);
        $component->call('saveEntry')->assertHasErrors('form.opponent')->assertSet('editor', 'request')->assertSee('Opponent required');
    }

    public function test_month_requests_exact_42_day_range_and_week_remains_available(): void
    {
        Livewire::test(PitchPlanningHarness::class)->set('mode', 'month')->assertSee('04.11.')
            ->set('mode', 'week')->assertSee('02.11.')->call('openDay', '2026-11-05')->assertSet('mode', 'day')->assertSet('date', '2026-11-05');
        $calls = array_values(array_filter(PitchPlanningHarness::$calls, fn ($call) => $call[0] === 'calendar'));
        $this->assertSame(['from' => '2026-10-26', 'to' => '2026-12-06'], $calls[1][1][0]);
    }

    public function test_no_manage_capability_hides_manual_block_and_drag(): void
    {
        PitchPlanningHarness::$manage = false;
        PitchPlanningHarness::$fixtureEntries[0]['editable'] = false;
        Livewire::test(PitchPlanningHarness::class)->assertDontSee('Sperre erfassen')->assertDontSee('Manuelle Belegung')
            ->assertDontSee('draggable="true"', false)->assertSee('Trainingsspiel anfragen')
            ->call('editEntry', self::entry()['key'])->assertDontSee('Verschiebung bestätigen');
    }

    public function test_missing_schema_is_visible_and_has_no_writable_controls(): void
    {
        PitchPlanningHarness::$failure = new HttpException(503);
        Livewire::test(PitchPlanningHarness::class)->assertSee('Die Sportplatzplanung ist noch nicht verfügbar.')
            ->assertDontSee('Trainingsspiel anfragen')->assertSet('entries', []);
    }

    public function test_request_review_uses_original_id_revision_and_decision(): void
    {
        PitchPlanningHarness::$fixtureEntries[0] = array_replace(self::entry(), ['key' => 'request:9', 'source' => 'request', 'source_id' => 9, 'status' => 'pending']);
        Livewire::test(PitchPlanningHarness::class)->call('editEntry', 'request:9')->assertSee('Genehmigen')
            ->call('decideEntry', 'approved')->assertSet('editor', null);
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'decide');
        $this->assertSame([9, ['revision' => 'opaque-r1', 'decision' => 'approved']], $call[1]);
    }

    public function test_manual_request_and_block_use_distinct_commands(): void
    {
        foreach (['manual' => 'createManual', 'request' => 'requestFriendly', 'block' => 'saveBlock'] as $kind => $method) {
            Livewire::test(PitchPlanningHarness::class)->call('createEntry', $kind)->call('saveEntry')->assertSet('editor', null);
            $this->assertContains($method, array_column(PitchPlanningHarness::$calls, 0));
        }
    }

    public function test_friendly_form_key_survives_validation_and_conflict_but_new_dialog_gets_new_key(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('createEntry', 'request');
        $key = $component->get('friendlyRequestId');
        $this->assertTrue(\Illuminate\Support\Str::isUuid($key));
        PitchPlanningHarness::$failure = ValidationException::withMessages(['opponent' => 'Required']);
        $component->call('saveEntry')->assertSet('friendlyRequestId', $key);
        PitchPlanningHarness::$failure = new HttpException(409);
        $component->call('saveEntry')->assertHasErrors('save')->assertSet('friendlyRequestId', $key);
        PitchPlanningHarness::$failure = null;
        $component->call('saveEntry')->assertSet('friendlyRequestId', null)->assertSet('editor', null);
        $component->call('createEntry', 'request');
        $this->assertNotSame($key, $component->get('friendlyRequestId'));
        $component->call('closeEditor')->call('createEntry', 'manual')->assertSet('friendlyRequestId', null);
    }

    public function test_friendly_key_cannot_be_replaced_by_browser(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('createEntry', 'request');
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('friendlyRequestId', 'forged-request-key');
    }

    public function test_english_labels_do_not_translate_user_content(): void
    {
        app()->setLocale('en');
        Livewire::test(PitchPlanningHarness::class)->assertSee('Daily schedule by pitch')->assertSee('Approved')
            ->call('editEntry', self::entry()['key'])->assertSee('Confirm move')->assertSee('U18 &lt;script&gt;unsafe&lt;/script&gt;', false)
            ->set('form.duration_minutes', 0)->call('saveEntry')->assertHasErrors('form.duration_minutes')->assertSee('Duration in minutes');
    }

    public function test_invalid_date_has_validation_feedback_without_render_failure(): void
    {
        Livewire::test(PitchPlanningHarness::class)->set('date', '2026-02-30')->assertHasErrors('date');
    }

    public function test_projection_and_revision_cannot_be_changed_by_browser(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('editEntry', self::entry()['key']);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('selected.revision', 'forged');
    }

    public function test_area_editor_preserves_revision_and_requires_both_language_names(): void
    {
        $component = Livewire::test(PitchPlanningHarness::class)->call('editArea', 1, 11)->assertSet('editor', 'area');
        $component->set('form.name_en', '')->call('saveEntry')->assertHasErrors('form.name_en');
        $component->set('form.name_en', 'North half')->call('saveEntry')->assertHasNoErrors()->assertSet('editor', null);
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'saveArea');
        $this->assertSame(11, $call[1][0]['id']);
        $this->assertSame('area-r2', $call[1][0]['revision']);
        $this->assertSame('Nordhälfte', $call[1][0]['name_de']);
    }

    public function test_cancellation_is_specific_to_selected_occurrence(): void
    {
        Livewire::test(PitchPlanningHarness::class)->call('editEntry', self::entry()['key'])->call('cancelEntry')->assertSet('editor', null);
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'cancel');
        $this->assertSame([self::entry()['key'], ['revision' => 'opaque-r1']], $call[1]);
    }

    public function test_long_block_is_allowed_but_regular_move_is_limited(): void
    {
        Livewire::test(PitchPlanningHarness::class)->call('createEntry', 'block')->set('form.duration_minutes', 44640)
            ->call('saveEntry')->assertHasNoErrors();
        Livewire::test(PitchPlanningHarness::class)->call('editEntry', self::entry()['key'])->set('form.duration_minutes', 44640)
            ->call('saveEntry')->assertHasErrors('form.duration_minutes');
    }

    public function test_editing_a_block_uses_the_block_contract_and_preserves_reason(): void
    {
        PitchPlanningHarness::$fixtureEntries[0] = array_replace(self::entry(), ['key' => 'block:3', 'source' => 'block', 'source_id' => 3, 'title' => 'Maintenance']);
        Livewire::test(PitchPlanningHarness::class)->call('editEntry', 'block:3')->assertSet('form.reason', 'Maintenance')
            ->set('form.duration_minutes', 2880)->call('saveEntry')->assertHasNoErrors();
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'saveBlock');
        $this->assertSame(3, $call[1][0]['id']);
        $this->assertSame('opaque-r1', $call[1][0]['revision']);
        $this->assertSame('Maintenance', $call[1][0]['reason']);
    }

    public function test_competition_teams_are_saved_separately_from_placement(): void
    {
        PitchPlanningHarness::$fixtureEntries[0] = array_replace(self::entry(), ['key' => 'competition:5', 'source' => 'competition', 'source_id' => 5]);
        Livewire::test(PitchPlanningHarness::class)->call('editEntry', 'competition:5')->assertSee('Mannschaften zuordnen')
            ->call('editCompetitionTeams', 'competition:5')->assertSet('editor', 'teams')
            ->set('form.team_season_ids', [2])->call('saveEntry')->assertHasNoErrors();
        $call = collect(PitchPlanningHarness::$calls)->first(fn ($call) => $call[0] === 'configureCompetition');
        $this->assertSame([5, ['revision' => 'opaque-r1', 'team_season_ids' => [2]]], $call[1]);
        $this->assertNotContains('move', array_column(PitchPlanningHarness::$calls, 0));
    }

    public function test_optional_visual_fixture_uses_real_blade_with_synthetic_data_only(): void
    {
        if (getenv('CLUBUNIO_PITCH_WEB_CAPTURE') !== '1') { $this->markTestSkipped('Opt-in generated visual fixture'); }
        $html = [];
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $component = Livewire::test(PitchPlanningHarness::class);
            foreach (['day', 'week', 'month'] as $mode) {
                $component->set('mode', $mode);
                $html[$locale.'-'.$mode] = $component->html();
            }
            $component->call('editEntry', self::entry()['key']);
            $html[$locale.'-editor'] = $component->html();
        }
        file_put_contents(storage_path('app/pitch-web-qa.json'), json_encode($html, JSON_THROW_ON_ERROR));
        $this->assertCount(8, $html);
    }
}

class PitchPlanningHarness extends PitchPlanning
{
    public static array $calls = [];
    public static array $fixtureEntries = [];
    public static ?\Throwable $failure = null;
    public static bool $manage = true;

    public static function canAccess(): bool { return true; }

    protected function submitFriendlyRequest(array $input): array
    {
        if (! $this->friendlyRequestId) throw new \LogicException('Missing form request key');
        if (array_key_exists('request_id', $input)) throw new \LogicException('Key must not reach domain input');
        return $this->callService('requestFriendly', $input);
    }

    public function mount(): void
    {
        $this->date = '2026-11-04';
        $this->refreshCalendar();
    }

    protected function callService(string $method, mixed ...$arguments): array
    {
        self::$calls[] = [$method, $arguments];
        if (self::$failure && (! in_array($method, ['calendar', 'options'], true) || (self::$failure instanceof HttpException && self::$failure->getStatusCode() === 503))) {
            throw self::$failure;
        }
        $options = ['fields' => [
            ['id' => 1, 'name' => 'Pitch One', 'available' => true, 'areas' => [['id' => 11, 'name' => 'Half A',
                'name_de' => 'Nordhälfte', 'name_en' => 'North half', 'x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 1, 'revision' => 'area-r2']]],
            ['id' => 2, 'name' => 'Pitch Two', 'available' => false, 'areas' => []],
        ], 'teams' => [['id' => 2, 'name' => 'U18']],
            'capabilities' => ['view' => true, 'request' => true, 'manage' => self::$manage, 'approve' => self::$manage]];

        return match ($method) { 'options' => $options, 'calendar' => $options + ['entries' => self::$fixtureEntries], default => self::$fixtureEntries[0] };
    }

    public function render(): View
    {
        return view('clubunio.pitch-planning.planner', $this->getViewData());
    }
}
