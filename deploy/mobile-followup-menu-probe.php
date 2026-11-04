<?php

namespace ClubUnioRelease;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Mobile\MobileFollowupModules;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Hash, Http, Mail, Queue};

/** Shared synthetic HTTP-kernel proof; no PHPUnit or provider dependency. */
final class FollowupMenuProbe
{
    public int $checks = 0;
    public string $stage = 'fixture';
    private $kernel;
    private $db;
    private function expect(mixed $actual, mixed $expected, string $label): void
    {
        $this->stage = $label.' [expected '.json_encode($expected).', actual '.json_encode($actual).']';
        if ($actual !== $expected) throw new \LogicException('Followup menu contract failed: '.$label);
        $this->checks++;
    }
    private function call(string $method, string $path, array $body = [], ?string $token = null, string $features = 'members-v1,registrations-v1,training-v1,sponsor-v1'): array
    {
        $this->stage = $method.' '.$path;
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => parse_url(config('app.url'), PHP_URL_HOST), 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CLUBUNIO_MOBILE_FEATURES' => $features];
        if ($token) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $request = Request::create(rtrim((string) config('app.url'), '/').'/api/mobile/v1/'.$path, $method, [], [], [], $server, json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);
        $this->stage .= ' [HTTP '.$response->getStatusCode().']';
        return [$response->getStatusCode(), json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR)];
    }
    public function run(bool $enabled): int
    {
        $this->db = DB::connection('clubunio');
        if ($this->db->getDriverName() !== 'pgsql' || $this->db->getDatabaseName() !== 'db_acdf8d_dclubora' || $this->db->transactionLevel() !== 0) throw new \LogicException('Standalone reviewed PostgreSQL required');
        foreach (MobileFollowupModules::CONTRACTS as [$flag]) {
            $this->expect((bool) config('clubunio-mobile.'.$flag, false), $enabled, 'actual stage flag '.$flag);
        }
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        foreach ([\App\ClubUnio\Services\RegistrationBlobStore::class, \App\ClubUnio\Services\AzureDocumentReader::class,
            \App\ClubUnio\Services\SponsorRunBexioClient::class, \App\ClubUnio\Services\BexioOidcClient::class] as $provider) {
            app()->bind($provider, fn () => throw new \LogicException('Provider forbidden in menu proof'));
        }
        config(['cache.default' => 'array', 'clubunio-mobile.uploads_enabled' => false]);
        $this->kernel = app(Kernel::class);
        $this->db->beginTransaction();
        try {
            $this->db->statement("SET LOCAL application_name TO 'clubunio-followup-menu-rollback'");
            $tenant = Tenant::create(['slug' => 'qa-menu-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic menu tenant']);
            $insert = fn ($table, $row) => $this->db->table('clubora.'.$table)->insertGetId($row + ['tenant_id' => $tenant->id]);
            foreach (array_keys(MobileFollowupModules::CONTRACTS) as $key) $this->db->table('clubora.mobile_module_options')->insert(['tenant_id' => $tenant->id, 'key' => $key, 'name' => $key]);
            $tokens = []; $actors = []; $members = []; $password = bin2hex(random_bytes(16));
            foreach (['admin' => 'admin', 'trainer' => 'trainer', 'lead' => 'technical_lead', 'parent' => 'member', 'helper' => 'member', 'ordinary' => 'member'] as $key => $role) {
                $account = new Account;
                $account->forceFill(['username' => 'qa-menu-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic menu '.$key, 'active' => true,
                    'api_only' => false, 'password' => Hash::make($password)])->save();
                $insert('tenant_accounts', ['account_id' => $account->id, 'role' => $role, 'portal_enabled' => true]);
                $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => $key, 'birth_date' => '1980-01-01']);
                $insert('account_member_links', ['account_id' => $account->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic-release']);
                [$status, $login] = $this->call('POST', 'session', ['username' => $account->username, 'password' => $password, 'device_name' => 'Synthetic menu proof']);
                $this->expect($status, 200, 'synthetic login '.$key);
                $tokens[$key] = $login['data']['token']; $actors[$key] = $account; $members[$key] = $member;
            }
            $season = $insert('seasons', ['name' => 'Synthetic menu season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
            $insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season', 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $season)]);
            $team = $insert('team_seasons', ['team_id' => $insert('teams', ['name' => 'Synthetic team']), 'season_id' => $season, 'label' => 'Synthetic team']);
            $insert('team_staff', ['team_season_id' => $team, 'member_id' => $members['trainer']]);
            $insert('technical_leads', ['team_season_id' => $team, 'member_id' => $members['lead']]);
            $child = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Child', 'birth_date' => now()->subYears(10)->toDateString()]);
            $insert('account_member_links', ['account_id' => $actors['parent']->id, 'member_id' => $child, 'kind' => 'parent', 'state' => 'approved', 'source' => 'synthetic-release']);
            $insert('team_players', ['team_season_id' => $team, 'member_id' => $child]);
            $registration = $insert('registrations', ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'team_season_id' => $team, 'technical_lead_member_id' => $members['lead']]);
            $insert('sponsor_run_statuses', ['code' => 'open', 'name' => 'Synthetic open']);
            $event = $insert('sponsor_run_events', ['season_id' => $season, 'title' => 'Synthetic event', 'place' => 'Synthetic',
                'event_date' => now()->addWeek()->toDateString(), 'registration_deadline' => now()->addDays(3)->toDateString(), 'status_code' => 'open', 'created_by' => $actors['admin']->id]);
            $insert('sponsor_run_event_teams', ['event_id' => $event, 'team_season_id' => $team]);
            $insert('sponsor_run_participants', ['event_id' => $event, 'member_id' => $child, 'team_season_id' => $team]);
            $helper = $insert('sponsor_run_helpers', ['event_id' => $event, 'account_id' => $actors['helper']->id, 'can_checkin' => true,
                'valid_from' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String()]);
            $base = 'clubs/'.$tenant->id;
            $menu = function (string $who, string $features = 'members-v1,registrations-v1,training-v1,sponsor-v1') use ($base, $tokens): array {
                [$status, $response] = $this->call('GET', $base.'/modules', [], $tokens[$who], $features);
                $this->expect($status, 200, 'menu response '.$who);
                return collect($response['data']['modules'])->keyBy('key')->all();
            };
            $admin = $menu('admin');
            foreach (array_keys(MobileFollowupModules::CONTRACTS) as $key) {
                $this->expect($admin[$key]['native'] ?? false, $enabled, 'admin native '.$key);
                $this->expect($admin[$key]['available'] ?? false, $enabled, 'admin available '.$key);
            }
            $old = $menu('admin', '');
            foreach (array_keys(MobileFollowupModules::CONTRACTS) as $key) $this->expect($old[$key]['available'] ?? false, false, 'old client '.$key);
            foreach (['members', 'registrations', 'training-plans', 'sponsor-run/events'] as $path) {
                [$status] = $this->call('GET', $base.'/'.$path, [], $tokens['admin']);
                $this->expect($status, $enabled ? 200 : 503, 'direct readiness '.$path);
            }
            if ($enabled) {
                [$status,$directory]=$this->call('GET',$base.'/members',[],$tokens['trainer']);
                $this->expect($status,200,'trainer personal directory status');
                $this->expect(array_column($directory['data']['Members'],'MitgliederID'),[$members['trainer']],'trainer directory excludes unrelated roster');
                $this->expect($directory['data']['PendingParentLinks'],0,'trainer pending parents own account only');
                [$status,$directory]=$this->call('GET',$base.'/members',[],$tokens['parent']);
                $this->expect($status,200,'parent personal directory status');
                $actual=array_column($directory['data']['Members'],'MitgliederID');sort($actual);$expected=[$members['parent'],$child];sort($expected);
                $this->expect($actual,$expected,'parent directory includes approved own child');
                $trainer = $menu('trainer');
                $this->expect($trainer['Mitglieder']['native'] ?? false, true, 'trainer real directory scope');
                $this->expect($trainer['Trainingsplaner']['native'] ?? false, true, 'trainer actual training team');
                $this->expect($trainer['MitgliederAnmeldungen']['native'] ?? false, false, 'trainer no registration review');
                $lead = $menu('lead');
                $this->expect($lead['MitgliederAnmeldungen']['native'] ?? false, true, 'lead actual registration scope');
                $parent = $menu('parent');
                $this->expect($parent['Sponsorenlauf']['native'] ?? false, true, 'parent own child event');
                $this->expect($parent['Trainingsplaner']['native'] ?? false, false, 'parent no training right');
                $this->expect($menu('helper')['Sponsorenlauf']['native'] ?? false, true, 'active helper event');
                $this->expect($menu('ordinary')['Sponsorenlauf']['native'] ?? false, false, 'marker no unrelated event');
                $this->db->table('clubora.sponsor_run_helpers')->where('id', $helper)->update(['valid_until' => now()->subMinute()->toIso8601String(), 'valid_from' => now()->subHour()->toIso8601String()]);
                $this->expect($menu('helper')['Sponsorenlauf']['native'] ?? false, false, 'expired helper');
                $this->db->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('account_id', $actors['parent']->id)->where('kind', 'parent')->update(['state' => 'revoked']);
                $this->expect($menu('parent')['Sponsorenlauf']['native'] ?? false, false, 'revoked parent child');
                $this->db->table('clubora.team_staff')->where('tenant_id', $tenant->id)->update(['active' => false]);
                $this->expect($menu('trainer')['Trainingsplaner']['native'] ?? false, false, 'revoked trainer assignment');
                $this->db->table('clubora.technical_leads')->where('tenant_id', $tenant->id)->update(['active' => false]);
                $this->db->table('clubora.registrations')->where('id', $registration)->update(['technical_lead_member_id' => null]);
                $this->expect($menu('lead')['MitgliederAnmeldungen']['native'] ?? false, false, 'revoked lead scope');
                $mobileProfile = $insert('access_profiles', ['name' => 'Synthetic menu only directory', 'permissions' => '[]', 'mobile_modules' => json_encode(['Mitglieder'])]);
                $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actors['admin']->id)->update(['mobile_profile_id' => $mobileProfile]);
                $filtered = $menu('admin');
                $this->expect($filtered['Mitglieder']['native'] ?? false, true, 'assigned mobile profile directory');
                $this->expect(isset($filtered['Sponsorenlauf']), false, 'assigned mobile profile hides sponsor');
                $this->expect(isset($filtered['Trainingsplaner']), false, 'assigned mobile profile hides training');
                $this->expect(isset($filtered['MitgliederAnmeldungen']), false, 'assigned mobile profile hides registrations');
                $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actors['admin']->id)->update(['mobile_profile_id' => null]);
                foreach (MobileFollowupModules::CONTRACTS as $closedKey => [$closedFlag]) {
                    config(['clubunio-mobile.'.$closedFlag => false]);
                    $partial = $menu('admin');
                    foreach (array_keys(MobileFollowupModules::CONTRACTS) as $key) {
                        $this->expect($partial[$key]['native'] ?? false, $key !== $closedKey, 'independent flag '.$closedKey.' / '.$key);
                    }
                    config(['clubunio-mobile.'.$closedFlag => true]);
                }
                $this->db->table('clubora.mobile_module_options')->where('tenant_id', $tenant->id)->where('key', 'Mitglieder')->update(['active' => false]);
                $this->expect(isset($menu('admin')['Mitglieder']), false, 'tenant module opt out');
            }
            $foreign = Tenant::create(['slug' => 'qa-menu-foreign-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic foreign']);
            [$status] = $this->call('GET', 'clubs/'.$foreign->id.'/modules', [], $tokens['ordinary']);
            $this->expect($status, 403, 'foreign tenant denied');
            $profile = $insert('access_profiles', ['name' => 'Synthetic maximum module profile', 'permissions' => json_encode(['members.view', 'registrations.view', 'training.view', 'sponsor_runs.view'])]);
            $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actors['ordinary']->id)->update(['role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
            [$status] = $this->call('GET', $base.'/modules', [], $tokens['ordinary']);
            $this->expect($status, 403, 'profile app remains denied');
            $this->expect(Http::recorded()->isEmpty(), true, 'no outgoing provider request');
            return $this->checks;
        } finally {
            $this->db->rollBack();
        }
    }
}
