<?php

namespace Tests\Feature;

use App\ClubUnio\Assistant\BackofficeAssistantService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BackofficeAssistant;
use App\ClubUnio\Services\BackofficeReadContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BackofficeAssistantPageTest extends TestCase
{
    private Account $actor;

    private Tenant $tenant;

    private MockInterface $service;

    private array $state;

    protected function setUp(): void
    {
        parent::setUp();
        // No application database, provider, or real account is used by these UI tests.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        Http::preventStrayRequests();
        $this->partialMock(BackofficeReadContext::class)->shouldReceive('duringRender')
            ->andReturnUsing(fn ($render) => $render());
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actor = new Account;
        $this->actor->forceFill(['id' => 41, 'active' => true, 'display_name' => 'Synthetic Office']);
        $this->tenant = new Tenant;
        $this->tenant->forceFill(['id' => 71, 'active' => true, 'name' => 'Synthetic Club', 'slug' => 'synthetic']);
        Filament::auth()->setUser($this->actor);
        Filament::setTenant($this->tenant, isQuiet: true);
        $this->service = Mockery::mock(BackofficeAssistantService::class);
        $this->app->instance(BackofficeAssistantService::class, $this->service);
        $this->service->shouldReceive('access')->with($this->actor, $this->tenant)->andReturnTrue()->byDefault();
        $this->state = $this->emptyState();
        $this->service->shouldReceive('state')->with($this->actor, $this->tenant, null)
            ->andReturnUsing(fn () => $this->state)->byDefault();
        $delta = json_decode(file_get_contents(base_path('deploy/assistant-ui-translations.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            $catalogs[$locale] = $delta[$locale] + json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
        }
        app('translator')->setLoaded(['*' => ['*' => $catalogs]]);
        app()->setLocale('en');
    }

    private function emptyState(): array
    {
        return ['conversation_id' => null, 'messages' => [], 'actions' => [], 'provider_enabled' => false,
            'budget' => ['limit_usd' => 10, 'used_usd' => 0], 'conversations' => []];
    }

    private function conversationState(): array
    {
        return array_replace($this->emptyState(), [
            'conversation_id' => 9, 'conversations' => [['id' => 9, 'title' => 'Synthetic history']],
            'messages' => [['role' => 'user', 'content' => '<script>unsafe</script> **plain text**'],
                ['role' => 'assistant', 'content' => 'Synthetic answer', 'results' => [
                    ['title' => 'Invoices', 'summary' => 'Two invoices', 'rows' => [
                        ['label' => 'Invoice 7 <img src=x onerror=alert(1)>', 'url' => '/invoices/7', 'open_amount' => '42.00', 'currency' => 'CHF'],
                        ['label' => 'Unsafe link', 'url' => 'javascript:alert(1)'],
                    ]],
                    ['title' => 'Reservations', 'summary' => 'One request', 'rows' => [
                        ['label' => 'Pitch 2', 'url' => '/pitch-planning?booking=2&date=2026-11-06', 'status_label' => 'Pending'],
                    ]],
                ]]],
            'actions' => [
                ['id' => 5, 'title' => 'Synthetic change <script>unsafe</script>', 'status' => 'pending',
                    'changes' => [['label' => 'Name', 'before' => 'Before <b>unsafe</b>', 'after' => 'After']], 'effects' => ['Internal record only']],
                ['id' => 6, 'title' => 'Second change', 'status' => 'pending', 'changes' => [], 'effects' => []],
                ['id' => 7, 'title' => 'Old change', 'status' => 'cancelled', 'changes' => [], 'effects' => []],
            ],
        ]);
    }

    public function test_page_metadata_uses_normal_filament_page_and_slug(): void
    {
        $this->assertSame('backoffice-assistant', BackofficeAssistant::getSlug());
        $this->assertNull(BackofficeAssistant::getNavigationGroup());
        $this->assertSame('heroicon-o-chat-bubble-left-right', BackofficeAssistant::getNavigationIcon());
        $this->assertTrue(BackofficeAssistant::canAccess());
    }

    public function test_provider_off_still_allows_explicit_local_daily_briefing(): void
    {
        $this->service->shouldNotReceive('ask');
        $this->service->shouldReceive('briefing')->once()->with($this->actor, $this->tenant, null)->andReturn($this->conversationState());
        Livewire::test(BackofficeAssistant::class)->assertSee('AI is disabled.')->assertSee('Daily overview')
            ->set('question', 'Question')->assertStatus(200)->call('send')->assertStatus(200)->assertHasErrors('assistant')
            ->call('dailyOverview')->assertHasNoErrors()->assertSet('conversationId', 9)->assertSee('Synthetic answer');
        Http::assertNothingSent();
    }

    public function test_messages_multiple_tools_previews_and_internal_links_are_escaped(): void
    {
        $this->state = $this->conversationState();
        $page = Livewire::test(BackofficeAssistant::class)->assertSee('Synthetic history')
            ->assertSee('Invoices')->assertSee('Reservations')->assertSee('Outstanding amount')
            ->assertSee('href="/invoices/7"', false)->assertSee('booking=2&amp;date=2026-11-06', false)
            ->assertSee('**plain text**')->assertDontSee('<script>unsafe</script>', false)
            ->assertDontSee('<b>unsafe</b>', false)->assertDontSee('<img src=x', false)->assertDontSee('href="javascript:', false)
            ->assertSee('Before change')->assertSee('After change')->assertSee('Internal record only')
            ->assertSee('wire:click="confirmAction(5)"', false)->assertSee('wire:click="cancelAction(6)"', false)
            ->assertDontSee('wire:click="confirmAction(7)"', false);
        $this->assertSame(2, substr_count($page->html(), 'wire:click="confirmAction('));
        $this->assertStringNotContainsString('wire:poll', $page->html());
        $this->assertStringContainsString('wire:loading.attr="disabled"', $page->html());
        $this->assertStringContainsString('role="log"', $page->html());
    }

    public function test_confirm_and_cancel_only_call_the_selected_action_and_replace_state(): void
    {
        $this->state = $this->conversationState();
        $confirmed = $this->state;
        $confirmed['actions'][0]['status'] = 'succeeded';
        $cancelled = $confirmed;
        $cancelled['actions'][1]['status'] = 'cancelled';
        $this->service->shouldReceive('confirm')->once()->with($this->actor, $this->tenant, 5)->andReturn($confirmed);
        $this->service->shouldReceive('cancel')->once()->with($this->actor, $this->tenant, 6)->andReturn($cancelled);
        Livewire::test(BackofficeAssistant::class)->call('confirmAction', 5)->assertSee('Action completed')
            ->assertDontSee('wire:click="confirmAction(5)"', false)->call('cancelAction', 6)
            ->assertDontSee('wire:click="confirmAction(6)"', false)->call('confirmAction', 999)->assertHasErrors('assistant')
            ->call('confirmAction', 5)->assertHasErrors('assistant');
    }

    public function test_service_owns_conversation_and_action_authorization_failures_are_safe(): void
    {
        $this->state = $this->conversationState();
        $this->service->shouldReceive('confirm')->once()->with($this->actor, $this->tenant, 5)->andThrow(new HttpException(403, 'private action details'));
        $this->service->shouldReceive('state')->once()->with($this->actor, $this->tenant, 999)->andThrow(new HttpException(404, 'private conversation details'));
        Livewire::test(BackofficeAssistant::class)->call('confirmAction', 5)->assertHasErrors('assistant')->assertDontSee('private action details')
            ->assertSet('assistantState.actions.0.status', 'pending')->call('selectConversation', 999)
            ->assertHasErrors('assistant')->assertSet('conversationId', null)->assertSet('assistantState', [])
            ->assertDontSee('Synthetic answer')->assertDontSee('private conversation details');
    }

    public function test_running_and_uncertain_actions_are_not_offered_for_confirmation(): void
    {
        $this->state = $this->conversationState();
        $this->state['actions'][0]['status'] = 'running';
        $this->state['actions'][1]['status'] = 'attention';
        $this->service->shouldNotReceive('confirm');
        Livewire::test(BackofficeAssistant::class)->assertSee('Action in progress')->assertSee('Action needs review')
            ->assertDontSee('wire:click="confirmAction(', false)->call('confirmAction', 5)->assertHasErrors('assistant')
            ->call('confirmAction', 6)->assertHasErrors('assistant');
    }

    public function test_new_conversation_and_history_use_explicit_state_calls_without_asking(): void
    {
        $this->state = $this->conversationState();
        $this->service->shouldReceive('state')->once()->with($this->actor, $this->tenant, 9)->andReturn($this->state);
        $this->service->shouldNotReceive('ask');
        $page = Livewire::test(BackofficeAssistant::class)->call('selectConversation', 9)->assertSet('conversationId', 9)->set('question', 'Discard draft');
        $this->state = $this->emptyState();
        $page->call('newConversation')->assertSet('conversationId', null)->assertSet('question', '')->assertSet('requestKey', null)
            ->assertSee('No messages in this conversation.');
    }

    public function test_question_uuid_is_retained_for_manual_uncertain_retry_even_after_local_briefing(): void
    {
        $this->state['provider_enabled'] = true;
        $seen = [];
        $answer = array_replace($this->conversationState(), ['provider_enabled' => true]);
        $this->service->shouldReceive('ask')->twice()->withArgs(function ($actor, $tenant, $conversation, $text, $key) use (&$seen) {
            $this->assertSame($this->actor, $actor);
            $this->assertSame($this->tenant, $tenant);
            $this->assertNull($conversation);
            $this->assertSame('My question', $text);
            $this->assertTrue(Str::isUuid($key));
            $seen[] = $key;

            return true;
        })->andReturnUsing(function () use (&$seen, $answer) {
            if (count($seen) === 1) {
                throw new RuntimeException('secret provider token');
            }

            return $answer;
        });
        $this->service->shouldReceive('briefing')->once()->with($this->actor, $this->tenant, null)->andReturn($answer);
        $page = Livewire::test(BackofficeAssistant::class)->set('question', '  My question  ')->call('prepareQuestion')->call('send')
            ->assertHasErrors('assistant')->assertDontSee('secret provider token')->assertSee('Retry question')->assertSet('question', 'My question');
        $key = $page->get('requestKey');
        $page->call('dailyOverview')->assertSet('requestKey', $key)->assertSet('conversationId', 9)
            ->call('prepareQuestion')->call('send')->assertHasNoErrors()->assertSet('question', '')->assertSet('requestKey', null);
        $this->assertSame([$key, $key], $seen);
    }

    public function test_a_different_question_gets_a_new_request_key(): void
    {
        $this->state['provider_enabled'] = true;
        $keys = [];
        $this->service->shouldReceive('ask')->twice()->andReturnUsing(function ($actor, $tenant, $conversation, $text, $key) use (&$keys) {
            $keys[] = $key;
            throw new RuntimeException('private');
        });
        Livewire::test(BackofficeAssistant::class)->set('question', 'One')->call('prepareQuestion')->call('send')
            ->set('question', 'Two')->call('prepareQuestion')->call('send');
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_request_identity_precedes_paid_call_and_survives_a_lost_success_response(): void
    {
        $this->state['provider_enabled'] = true;
        $page = Livewire::test(BackofficeAssistant::class)->set('question', 'Network retry')->call('prepareQuestion');
        $key = $page->get('requestKey');
        $this->assertTrue(Str::isUuid($key));
        $this->service->shouldReceive('ask')->twice()->with($this->actor, $this->tenant, null, 'Network retry', $key)
            ->andReturn(array_replace($this->conversationState(), ['provider_enabled' => true]));

        // Execute once without delivering its response/snapshot to the browser.
        $page->instance()->send();
        $page->call('send')->assertSet('conversationId', 9)->assertSet('requestKey', null)->assertSet('question', '');
    }

    public function test_unprepared_question_never_calls_paid_service(): void
    {
        $this->state['provider_enabled'] = true;
        $this->service->shouldNotReceive('ask');
        Livewire::test(BackofficeAssistant::class)->set('question', 'Unprepared')->call('send')
            ->assertHasErrors('assistant')->assertSee('Please submit the question again.');
    }

    public function test_blank_and_oversized_questions_never_reach_service(): void
    {
        $this->state['provider_enabled'] = true;
        $this->service->shouldNotReceive('ask');
        Livewire::test(BackofficeAssistant::class)->set('question', '  ')->call('send')->assertHasErrors('question')
            ->assertSee('Enter a question')->set('question', str_repeat('a', 4001))->call('send')
            ->assertHasErrors('question')->assertSee('at most 4000 characters')->assertSet('requestKey', null);
    }

    public function test_budget_limit_blocks_ai_but_not_local_briefing(): void
    {
        $this->state['provider_enabled'] = true;
        $this->state['budget']['used_usd'] = 10;
        $this->service->shouldNotReceive('ask');
        $this->service->shouldReceive('briefing')->once()->with($this->actor, $this->tenant, null)->andReturn($this->state);
        Livewire::test(BackofficeAssistant::class)->assertSee('AI cost limit reached.')->assertSee('10.0000 / 10.00 USD')
            ->set('question', 'Question')->call('send')->assertHasErrors('assistant')->call('dailyOverview')->assertHasNoErrors();
    }

    public function test_mount_and_access_errors_do_not_expose_internals(): void
    {
        $this->service->shouldReceive('state')->andThrow(new RuntimeException('SQLSTATE private host password'));
        Livewire::test(BackofficeAssistant::class)->assertSee('The assistant is currently unavailable.')
            ->assertDontSee('SQLSTATE')->assertSet('stateReady', false);
        $this->service->shouldReceive('access')->andThrow(new RuntimeException('secret credentials'));
        $this->assertFalse(BackofficeAssistant::canAccess());
        Livewire::test(BackofficeAssistant::class)->assertForbidden()->assertDontSee('secret credentials');
    }

    public function test_sanitized_assistant_validation_message_is_shown_without_other_fields(): void
    {
        $this->service->shouldReceive('briefing')->once()->andThrow(ValidationException::withMessages([
            'assistant' => 'Localized safe message <b>plain</b>', 'provider' => 'secret token',
        ]));
        Livewire::test(BackofficeAssistant::class)->call('dailyOverview')->assertHasErrors('assistant')
            ->assertSee('Localized safe message')->assertDontSee('<b>plain</b>', false)->assertDontSee('secret token');
    }

    public function test_tenant_switch_clears_conversation_draft_and_blocks_stale_confirmation(): void
    {
        $this->state = $this->conversationState();
        $page = Livewire::test(BackofficeAssistant::class)->set('question', 'Old tenant draft');
        $other = new Tenant;
        $other->forceFill(['id' => 72, 'active' => true, 'name' => 'Other Club']);
        Filament::setTenant($other, isQuiet: true);
        $this->service->shouldReceive('access')->with($this->actor, $other)->andReturnTrue();
        $this->service->shouldReceive('state')->once()->with($this->actor, $other, null)->andReturn($this->emptyState());
        $this->service->shouldNotReceive('confirm');
        $page->call('confirmAction', 5)->assertSet('conversationId', null)->assertSet('question', '')
            ->assertSet('requestKey', null)->assertSet('assistantState.messages', [])->assertDontSee('Synthetic answer');
    }

    public function test_actor_switch_clears_state_and_does_not_send_old_question(): void
    {
        $this->state['provider_enabled'] = true;
        $page = Livewire::test(BackofficeAssistant::class)->set('question', 'Old account question');
        $other = new Account;
        $other->forceFill(['id' => 42, 'active' => true]);
        Filament::auth()->setUser($other);
        $this->service->shouldReceive('access')->with($other, $this->tenant)->andReturnTrue();
        $this->service->shouldReceive('state')->once()->with($other, $this->tenant, null)->andReturn($this->emptyState());
        $this->service->shouldNotReceive('ask');
        $page->call('send')->assertSet('question', '')->assertSet('conversationId', null);
    }

    public function test_access_is_rechecked_on_livewire_requests_and_denial_is_fail_closed(): void
    {
        $page = Livewire::test(BackofficeAssistant::class);
        $this->service->shouldReceive('access')->andReturnFalse();
        $this->service->shouldNotReceive('briefing');
        $this->assertFalse(BackofficeAssistant::canAccess());
        $page->call('dailyOverview')->assertForbidden();
    }

    public function test_missing_actor_or_tenant_is_denied_without_service_calls(): void
    {
        Filament::setTenant(null, isQuiet: true);
        $this->service->shouldNotReceive('state');
        $this->assertFalse(BackofficeAssistant::canAccess());
        Livewire::test(BackofficeAssistant::class)->assertForbidden();
    }

    #[DataProvider('lockedProperties')]
    public function test_client_cannot_replace_server_state_or_request_identity(string $property, mixed $value): void
    {
        $page = Livewire::test(BackofficeAssistant::class);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($property, $value);
    }

    public static function lockedProperties(): array
    {
        return [['conversationId', 999], ['contextKey', '999:999'], ['assistantState.provider_enabled', true],
            ['requestKey', 'fake-key'], ['requestText', 'forged'], ['requestConversationId', 999], ['stateReady', true]];
    }

    public function test_both_enabled_languages_and_placeholders_are_complete_and_do_not_translate_user_content(): void
    {
        $delta = json_decode(file_get_contents(base_path('deploy/assistant-ui-translations.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            $this->assertSame(array_keys($delta['en']), array_keys($delta[$locale]));
            foreach ($delta[$locale] as $key => $value) {
                $this->assertNotSame('', trim($value));
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale.': '.$key);
            }
            app()->setLocale($locale);
            $this->state = array_replace($this->conversationState(), ['provider_enabled' => true]);
            $page = Livewire::test(BackofficeAssistant::class)->assertSee($delta[$locale]['ClubUnio Assistant'])
                ->assertSee($delta[$locale]['Daily overview'])->assertSee($delta[$locale]['Confirm action'])
                ->assertSee('Synthetic answer')->call('useExample', 'invoices')
                ->assertSet('question', $delta[$locale]['Which invoices are still outstanding?']);
            if (getenv('CLUBUNIO_ASSISTANT_PREVIEW') === '1') {
                file_put_contents(storage_path('app/assistant-ui-preview-'.$locale.'.html'),
                    '<!doctype html><html lang="'.$locale.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                    .'<style>'.file_get_contents(public_path('css/filament/filament/app.css')).file_get_contents(public_path('css/svs.css'))
                    .'body{padding:24px;background:#fff;color:#18181b;font-family:Arial,sans-serif} [wire\\:loading]{display:none} .fi-btn-icon{width:1.25rem;height:1.25rem}</style></head><body>'
                    .$page->html().'</body></html>');
            }
        }
    }

    #[DataProvider('unsafeUrls')]
    public function test_result_links_reject_unsafe_or_external_urls(string $url): void
    {
        $this->assertNull((new BackofficeAssistant)->resultUrl($url));
    }

    public static function unsafeUrls(): array
    {
        return [['javascript:alert(1)'], ['data:text/html,unsafe'], ['//evil.example/path'], ['/\\evil.example'],
            ['/%2fother.example'], ['/%5cevil.example'], ["/\nevil.example"], ['https://evil.example/path'],
            ['https://user:password@localhost/path'], ['file:///etc/passwd']];
    }

    public function test_local_result_urls_and_nested_briefing_sections_are_supported(): void
    {
        $page = new BackofficeAssistant;
        $this->assertSame('/members/7', $page->resultUrl('/members/7'));
        $this->assertSame(url('/members/7'), $page->resultUrl(url('/members/7')));
        $result = ['title' => 'Overview', 'summary' => 'Today', 'rows' => [], 'sections' => [
            'members' => ['title' => 'Members', 'summary' => 'One', 'rows' => [['label' => 'Member Seven', 'url' => '/members/7']]],
        ]];
        $this->state['messages'] = [['role' => 'assistant', 'content' => 'Local briefing', 'results' => [$result]]];
        Livewire::test(BackofficeAssistant::class)->assertSee('Overview')->assertSee('Members')
            ->assertSee('Member Seven')->assertSee('href="/members/7"', false);
    }

    #[DataProvider('briefingCountCases')]
    public function test_briefing_renders_module_counts_in_both_languages_even_without_rows(string $locale, bool $empty): void
    {
        app()->setLocale($locale);
        $counts = ['matching' => $empty ? 0 : 31, 'returned' => $empty ? 0 : 1];
        foreach (['members', 'registrations', 'expenses', 'invoices', 'bus', 'pitch'] as $module) {
            $counts[$module] = ['matching' => $empty ? 0 : 25, 'returned' => $empty ? 0 : 3];
        }
        $counts['invoices']['overdue'] = null;
        foreach (['bus', 'pitch'] as $module) {
            $counts[$module][$module.'_pending_all_dates'] = $empty ? 0 : 9;
            $counts[$module][$module.'_pending_before_date'] = $empty ? 0 : 4;
            $counts[$module][$module.'_on_date'] = $empty ? 0 : 2;
        }
        $result = ['title' => 'Synthetic daily overview', 'summary' => 'Synthetic scope', 'counts' => $counts,
            'rows' => $empty ? [] : [['module' => 'members', 'label' => 'Synthetic Member', 'url' => '/members/7']]];
        $this->state['messages'] = [['role' => 'assistant', 'content' => 'Local briefing', 'results' => [$result]]];
        $page = Livewire::test(BackofficeAssistant::class)->assertSee(__('Module counts'))
            ->assertSee(__('Matching records'))->assertSee(__('Records shown'))
            ->assertSee(__('Pending requests (all dates)'))->assertSee(__('Not available'));

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $groups = $xpath->query('//*[@data-assistant-count-module]');
        $this->assertCount(6, $groups);
        foreach ($groups as $group) {
            $module = $group->getAttribute('data-assistant-count-module');
            foreach ($counts[$module] as $key => $value) {
                $node = $xpath->query('.//*[@data-assistant-count="'.$key.'"]/dd', $group);
                $this->assertCount(1, $node, $module.': '.$key);
                $this->assertSame($value === null ? __('Not available') : (string) $value, trim($node->item(0)->textContent));
            }
        }
        $this->assertStringNotContainsString('"matching":', $page->html());
        if (! $empty) {
            $page->assertSee('href="/members/7"', false)->assertSee('Synthetic Member');
        }
    }

    public static function briefingCountCases(): array
    {
        return [['de', true], ['en', true], ['de', false], ['en', false]];
    }

    public function test_count_projection_does_not_invent_missing_values_or_render_unstructured_payloads(): void
    {
        $page = new BackofficeAssistant;
        $this->assertSame([], $page->resultCountGroups([]));
        $this->assertSame([], $page->resultCountGroups(['counts' => ['members' => ['unexpected' => ['secret' => 'not a count']]]]));
        $groups = $page->resultCountGroups(['counts' => ['members' => ['matching' => 0],
            'invoices' => ['matching' => null, 'returned' => '<script>unsafe</script>', 'overdue' => ['unknown' => true]]]]);
        $this->assertSame([['key' => 'matching', 'label' => 'Matching records', 'value' => 0]], $groups[0]['metrics']);
        $this->assertSame([['key' => 'matching', 'label' => 'Matching records', 'value' => 'Not available']], $groups[1]['metrics']);
    }
}
