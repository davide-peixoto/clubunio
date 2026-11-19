<?php

use App\ClubUnio\Models\{Account, ClubEvent, Member, Tenant};
use App\ClubUnio\Services\{CampaignContact, CampaignOperations, CampaignResponses, MemberCampaignService};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
$url = config('app.url');
$name = $url === 'https://dev.clubunio.ch' ? 'clubunio_dev' : ($url === 'https://stage.clubunio.com' ? 'clubunio_stage' : null);
if (! $name || ! app()->environment('staging') || $db->selectOne('select current_database() as name')->name !== $name) exit(1);
Http::preventStrayRequests();
Mail::fake();
$db->beginTransaction();
$exit = 0;
try {
    $db->selectOne('select pg_advisory_xact_lock(67162311)');
    $suffix = bin2hex(random_bytes(8));
    $tenant = new Tenant;
    $tenant->forceFill(['slug' => 'campaign-ops-'.$suffix, 'name' => 'Synthetic campaigns'])->save();
    $other = new Tenant;
    $other->forceFill(['slug' => 'campaign-other-'.$suffix, 'name' => 'Synthetic other'])->save();
    $actor = new Account;
    $actor->forceFill(['username' => "campaign-test-admin-$suffix@example.invalid", 'display_name' => 'Synthetic campaign admin',
        'active' => true, 'password' => bcrypt($suffix)])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $service = app(MemberCampaignService::class);
    $ops = app(CampaignOperations::class);
    $responses = app(CampaignResponses::class);
    $require = static function (bool $ok, string $step): void { if (! $ok) throw new RuntimeException($step); };
    $denied = static function (Closure $fn, string $step): void {
        try { $fn(); } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\RecordsNotFoundException|\Illuminate\Database\Eloquent\ModelNotFoundException) { return; }
        throw new RuntimeException($step);
    };
    $makeMember = static function (int $n, bool $minor = false) use ($tenant, $suffix): Member {
        $member = new Member;
        $member->forceFill(['tenant_id' => $tenant->id, 'member_number' => $n, 'first_name' => 'Synthetic', 'last_name' => "Recipient $n",
            'birth_date' => $minor ? '2015-01-01' : '1985-01-01', 'email' => "campaign-test-$n-$suffix@example.invalid",
            'mother_name' => 'Synthetic Parent', 'mother_email' => "campaign-test-parent-$n-$suffix@example.invalid",
            'street' => 'Test street', 'house_number' => '1', 'postal_code' => '4000', 'city' => 'Test city'])->save();
        return $member;
    };
    $member = $makeMember(1);
    $id = $service->create($actor, $tenant, 'Synthetic acknowledgement', 'email', false);
    $service->saveContent($actor, $tenant, $id, 'acknowledgement', ['de' => ['subject' => 'Test DE'], 'en' => ['subject' => 'Test EN']]);
    $service->recipient($actor, $tenant, $id, $member->id, 'email');
    $template = $ops->saveTemplate($actor, $tenant, $id, 'Synthetic reusable copy');
    $ops->applyTemplate($actor, $tenant, $id, $template);
    $ops->updateTemplate($actor, $tenant, $id, $template, 1);
    $denied(fn () => $ops->updateTemplate($actor, $tenant, $id, $template, 1), 'stale template revision accepted');
    $denied(fn () => $ops->preview($actor, $other, $id), 'foreign tenant accepted');
    $require(!$ops->preview($actor, $tenant, $id)['issues'], 'preview passed');
    $service->release($actor, $tenant, $id);
    $denied(fn () => $service->release($actor, $tenant, $id), 'duplicate release accepted');
    $dispatch = $ops->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('campaign_id', $id)->sole();
    $ops->table('campaign_dispatches')->where('id', $dispatch->id)->update(['due_at' => now()->subSeconds(2)]);
    $require($ops->runOne($dispatch->id), 'initial dispatch processed');
    $require(!$ops->runOne($dispatch->id), 'no duplicate dispatch');
    $require($ops->table('campaign_dispatches')->where('id', $dispatch->id)->value('outcome') === 'memory_simulated', 'memory only');
    $ops->table('campaign_dispatches')->where('id', $dispatch->id)->update(['accepted_at' => now()->subDays(8), 'attempted_at' => now()->subDays(8)]);
    $require($ops->reminders($actor, $tenant, $id) === 1 && $ops->reminders($actor, $tenant, $id) === 0, 'reminder idempotency');
    $row = $service->recipients($tenant, $id)->sole();
    $responses->opened($tenant, $row);
    $service->acknowledge($tenant, Crypt::decryptString($row->token_encrypted));
    $reminder = $ops->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('sequence', 1)->sole();
    $ops->table('campaign_dispatches')->where('id', $reminder->id)->update(['due_at' => now()->subSeconds(2)]);
    $require($ops->runOne($reminder->id) && $ops->table('campaign_dispatches')->where('id', $reminder->id)->value('state') === 'cancelled', 'response cancels reminder');
    $feedback = ['event_id' => (string) Str::uuid(), 'dispatch_id' => $dispatch->id, 'state' => 'bounced', 'address_hash' => $dispatch->address_hash];
    $ops->feedback($feedback);
    $ops->feedback($feedback);
    $require($ops->table('campaign_delivery_events')->where('source_key', $feedback['event_id'])->count() === 1, 'feedback replay idempotency');
    $ops->feedback(array_replace($feedback, ['event_id' => (string) Str::uuid(), 'state' => 'delivered']));
    $require($ops->table('campaign_dispatches')->where('id', $dispatch->id)->value('state') === 'bounced', 'late delivered does not undo bounce');
    config(['clubunio-campaign-mail.feedback_secret' => bin2hex(random_bytes(32))]);
    $body = json_encode(array_replace($feedback, ['event_id' => (string) Str::uuid()]), JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $webhook = \Illuminate\Http\Request::create('/campaign-feedback', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_CAMPAIGN_TIMESTAMP' => $timestamp,
        'HTTP_X_CAMPAIGN_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, config('clubunio-campaign-mail.feedback_secret')),
    ], $body);
    $require((new \App\ClubUnio\Http\CampaignFeedbackController)($webhook, $ops)->getStatusCode() === 200, 'signed feedback accepted');
    $webhook->headers->set('X-Campaign-Signature', 'invalid');
    $denied(fn () => (new \App\ClubUnio\Http\CampaignFeedbackController)($webhook, $ops), 'invalid signature accepted');
    $require((int) $ops->cockpit($actor, $tenant, $id)['summary']['responded'] === 1, 'cockpit response count');
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
    \Filament\Facades\Filament::auth()->setUser($actor);
    \Filament\Facades\Filament::setTenant($tenant);
    $html = \Livewire\Livewire::mount(\App\ClubUnio\Pages\MemberCampaigns::class, ['selected' => $id, 'kind' => 'acknowledgement']);
    $require(str_contains((string) $html, trans('campaign_ops.cockpit')) && str_contains((string) $html, trans('campaign_ops.status_note')),
        'authenticated campaign cockpit renders');
    $ops->archiveTemplate($actor, $tenant, $template, 2);
    $require(!$ops->templates($tenant)->where('id', $template)->exists(), 'template archived');

    $minor = $makeMember(2, true);
    $post = $service->create($actor, $tenant, 'Synthetic guardian post', 'post', false);
    $service->saveContent($actor, $tenant, $post, 'acknowledgement', []);
    $service->recipient($actor, $tenant, $post, $minor->id, 'post');
    $recipient = $service->recipients($tenant, $post)->sole();
    $require((bool) $ops->preview($actor, $tenant, $post)['issues'], 'unverified minor blocked');
    app(CampaignContact::class)->verify($actor, $tenant, $post, $recipient->id, ['parent' => 'mother', 'name' => 'Synthetic Parent',
        'email' => $minor->mother_email, 'street' => 'Test street 1', 'postal_code' => '4000', 'city' => 'Test city',
        'evidence' => 'Synthetic verified guardian and postal address']);
    $service->release($actor, $tenant, $post);
    $request = \Illuminate\Http\Request::create(config('app.url'));
    $request->setUserResolver(fn () => $actor);
    $pdf = app(\App\ClubUnio\Http\MemberCampaignController::class)->letters($request, $tenant, $post, $service);
    $require(str_starts_with($pdf->getContent(), '%PDF-'), 'guardian QR PDF');
    $recipient = $service->recipients($tenant, $post)->sole();
    $require((bool) $recipient->printed_at && !$recipient->posted_at, 'PDF not postal dispatch confirmation');
    $minor->forceFill(['mother_email' => 'changed@example.invalid'])->save();
    $require($service->deliveryMember($tenant, $recipient) === null, 'changed guardian invalidates token');

    $eventMember = $makeMember(3);
    $event = new ClubEvent;
    $event->forceFill(['tenant_id' => $tenant->id, 'name' => 'Synthetic event', 'location' => 'Test location', 'active' => true])->save();
    $eventCampaign = $service->create($actor, $tenant, 'Synthetic event response', 'email', false);
    $service->saveContent($actor, $tenant, $eventCampaign, 'event', []);
    $ops->configure($actor, $tenant, $eventCampaign, $event->id, 7);
    $service->recipient($actor, $tenant, $eventCampaign, $eventMember->id, 'email');
    $service->release($actor, $tenant, $eventCampaign);
    $eventRow = $service->recipients($tenant, $eventCampaign)->sole();
    $responses->submit($tenant, Crypt::decryptString($eventRow->token_encrypted), ['answer' => 'yes', 'note' => 'Synthetic reply']);
    $require(!$db->table('clubunio.event_guests')->where('tenant_id', $tenant->id)->exists(), 'response requires review');
    $responses->review($actor, $tenant, $eventCampaign, $eventRow->id, true);
    $require($db->table('clubunio.event_guests')->where('tenant_id', $tenant->id)->where('member_id', $eventMember->id)->count() === 1, 'reviewed event integration');
    $denied(fn () => $responses->review($actor, $tenant, $eventCampaign, $eventRow->id, true), 'duplicate response review accepted');
    $season = $db->table('clubunio.seasons')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic season']);
    $team = $db->table('clubunio.teams')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic team']);
    $teamSeason = $db->table('clubunio.team_seasons')->insertGetId(['tenant_id' => $tenant->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Synthetic team season']);
    $db->table('clubunio.sponsor_run_statuses')->insert(['tenant_id' => $tenant->id, 'code' => 'open', 'name' => 'Synthetic open']);
    $run = $db->table('clubunio.sponsor_run_events')->insertGetId(['tenant_id' => $tenant->id, 'season_id' => $season,
        'title' => 'Synthetic sponsor run', 'place' => 'Synthetic field', 'event_date' => now()->addDays(14)->toDateString(),
        'registration_deadline' => now()->addDays(7)->toDateString(), 'status_code' => 'open', 'created_by' => $actor->id]);
    $sponsorMember = $makeMember(4);
    $participant = $db->table('clubunio.sponsor_run_participants')->insertGetId(['tenant_id' => $tenant->id, 'event_id' => $run,
        'member_id' => $sponsorMember->id, 'team_season_id' => $teamSeason]);
    $sponsorCampaign = $service->create($actor, $tenant, 'Synthetic sponsor response', 'email', false);
    $service->saveContent($actor, $tenant, $sponsorCampaign, 'sponsor', []);
    $ops->configure($actor, $tenant, $sponsorCampaign, $run, 7);
    $service->recipient($actor, $tenant, $sponsorCampaign, $sponsorMember->id, 'email');
    $service->release($actor, $tenant, $sponsorCampaign);
    $sponsorRow = $service->recipients($tenant, $sponsorCampaign)->sole();
    $responses->submit($tenant, Crypt::decryptString($sponsorRow->token_encrypted), [
        'answer' => 'yes', 'amount_chf' => '12.50', 'amount_cents' => 99999, 'amount_type' => 'fixed',
        'sponsor_name' => 'Synthetic sponsor', 'first_name' => 'Synthetic', 'last_name' => 'Sponsor',
        'street' => 'Test street', 'house_number' => '1', 'postal_code' => '4000', 'city' => 'Test city',
        'country' => 'CH', 'email' => "campaign-test-sponsor-$suffix@example.invalid",
    ]);
    $require(!$db->table('clubunio.sponsor_run_pledges')->where('tenant_id', $tenant->id)->exists(), 'pledge requires review');
    $responses->review($actor, $tenant, $sponsorCampaign, $sponsorRow->id, true);
    $pledge = $db->table('clubunio.sponsor_run_pledges')->where('tenant_id', $tenant->id)->where('participant_id', $participant)->sole();
    $require((int) $pledge->amount_cents === 1250, 'reviewed CHF pledge uses domain logic, not supplied cents');
    $require(!$db->table('clubunio.invoices')->where('tenant_id', $tenant->id)->exists(), 'no automatic invoices');
    $require(Http::recorded()->isEmpty(), 'no outbound HTTP');
    echo "Campaign cockpit, test dispatch, reminders, feedback, templates, verified guardian PDF, event and sponsor review passed; rollback only. No SMTP.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    if (get_class($error) === RuntimeException::class) fwrite(STDERR, $error->getMessage()."\n");
    if ($error instanceof \Illuminate\Database\QueryException) {
        fwrite(STDERR, $error->getPrevious()?->getMessage()."\n");
    }
    $exit = 1;
} finally {
    $db->rollBack();
}
exit($exit);
