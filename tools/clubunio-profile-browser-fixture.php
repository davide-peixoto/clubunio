<?php

// CLI-only, explicit synthetic fixture for a browser acceptance; never deploy publicly.
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ExpenseService, InvoiceWorkflowService, PermissionCatalog};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Crypt, DB};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
$file = storage_path('app/profile-browser-acceptance.json');
$mode = $argv[1] ?? 'status';
$fingerprint = function (array $exclude = [], ?int $tenant = null) use ($db) {
    return hash('sha256', json_encode([
        $db->table('clubora.accounts')->whereNotIn('id', $exclude)->orderBy('id')->get(['id', 'active', 'revision']),
        $db->table('clubora.tenant_accounts')->when($tenant, fn ($q) => $q->where('tenant_id', '<>', $tenant))->orderBy('id')->get(),
        $db->table('clubora.access_profiles')->when($tenant, fn ($q) => $q->where('tenant_id', '<>', $tenant))->orderBy('id')->get(),
        $db->table('clubora.account_roles')->orderBy('code')->get(),
    ], JSON_THROW_ON_ERROR));
};

if ($mode === 'create') {
    if (file_exists($file)) { throw new LogicException('Existing fixture must be cleaned first'); }
    $password = getenv('CLUBUNIO_QA_PASSWORD');
    if (! $password || strlen($password) < 24) { throw new LogicException('Temporary password required in environment'); }
    $before = $fingerprint();
    $state = $db->transaction(function () use ($db, $password, $before) {
        $suffix = bin2hex(random_bytes(6));
        $tenant = Tenant::create(['slug' => 'browser-qa-'.$suffix, 'name' => 'ClubUnio Browser-Abnahme']);
        $insert = fn ($table, $data) => $db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $tenant->id]);
        $finance = ['members.view', 'teams.view', 'seasons.view'];
        foreach (['invoices', 'expenses', 'contributions', 'credits'] as $module) {
            foreach (['view', 'create', 'update', 'copy', 'deactivate', 'restore', 'download', 'upload', 'export'] as $function) {
                if (isset(PermissionCatalog::functions($module)[$function])) { $finance[] = $module.'.'.$function; }
            }
        }
        $accounts = [];
        $usernames = [];
        foreach (['read' => ['Geschäftsstelle nur lesen', PermissionCatalog::readOnly()], 'finance' => ['Finanzen bearbeiten', $finance]] as $key => [$name, $permissions]) {
            $profile = $insert('access_profiles', ['name' => $name, 'permissions' => json_encode($permissions), 'active' => true, 'revision' => 1]);
            $account = new Account;
            $account->forceFill(['username' => 'qa-'.$key.'-'.$suffix, 'display_name' => 'Abnahme '.$name,
                'password' => password_hash($password, PASSWORD_BCRYPT), 'active' => true])->save();
            $insert('tenant_accounts', ['account_id' => $account->id, 'role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
            $accounts[$key] = $account->id;
            $usernames[$key] = $account->username;
        }
        $member = $insert('members', ['first_name' => 'Browser', 'last_name' => 'Testmitglied']);
        $category = $insert('invoice_categories', ['name' => 'Testbeitrag', 'code' => 'qa']);
        foreach (['draft' => 'Entwurf', 'released' => 'Freigegeben', 'cancelled' => 'Storniert'] as $code => $name) {
            $insert('invoice_workflow_statuses', compact('name', 'code'));
        }
        $insert('invoice_line_statuses', ['name' => 'Pendent', 'code' => 'P']);
        foreach (['O' => 'Offen', 'F' => 'Freigegeben', 'Z' => 'Zur Zahlung', 'R' => 'Abgelehnt', 'B' => 'Bezahlt'] as $code => $name) {
            $insert('expense_statuses', compact('name', 'code'));
        }
        $season = $insert('seasons', ['name' => 'Testsaison 2026/2027']);
        $team = $insert('teams', ['name' => 'Testmannschaft']);
        $teamSeason = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Testmannschaft 2026/2027']);
        $round = $insert('expense_rounds', ['name' => 'Testrunde', 'code' => 'qa']);
        $bank = $insert('member_bank_accounts', ['member_id' => $member, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $actor = Account::findOrFail($accounts['finance']);
        $invoices = app(InvoiceWorkflowService::class);
        $invoice = $invoices->create($actor, $tenant, ['member_id' => $member, 'invoice_category_id' => $category, 'tax_mode' => 'exempt', 'notes' => 'Browser-Abnahme Ausgangsstand']);
        $invoice = $invoices->change($actor, $tenant, $invoice->id, $invoice->revision, 'line', ['description' => 'Testposition', 'gross_amount' => '25.00', 'discount' => '0']);
        $expense = app(ExpenseService::class)->save($actor, $tenant, null, ['member_id' => $member, 'team_season_id' => $teamSeason, 'round_id' => $round, 'bank_account_id' => $bank, 'notes' => 'Browser-Abnahme Spesen']);
        return ['tenant' => $tenant->id, 'slug' => $tenant->slug, 'accounts' => $accounts, 'usernames' => $usernames,
            'member' => $member, 'invoice' => $invoice->id, 'expense' => $expense->id, 'before' => $before];
    });
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    unset($state['before']);
    echo json_encode($state, JSON_PRETTY_PRINT)."\n";
    exit;
}
if (! file_exists($file)) { throw new LogicException('No fixture state'); }
$state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
if (! str_starts_with($state['slug'], 'browser-qa-') || ! $db->table('clubora.tenants')->where('id', $state['tenant'])->where('slug', $state['slug'])->exists()) {
    throw new LogicException('Fixture identity mismatch');
}
if ($fingerprint(array_values($state['accounts']), $state['tenant']) !== $state['before']) {
    throw new LogicException('Existing rights changed externally; review before cleanup');
}
if ($mode === 'cleanup') {
    $db->transaction(function () use ($db, $state) {
        // Only the explicitly identified synthetic tenant and its two accounts.
        foreach (['object_changes', 'invoice_lines', 'invoices', 'expense_lines', 'expenses', 'member_bank_accounts',
            'members', 'team_seasons', 'teams', 'seasons', 'expense_rounds', 'expense_statuses', 'invoice_line_statuses',
            'invoice_workflow_statuses', 'invoice_categories', 'tenant_accounts', 'access_profiles'] as $table) {
            $db->table('clubora.'.$table)->where('tenant_id', $state['tenant'])->delete();
        }
        $db->table('clubora.tenants')->where('id', $state['tenant'])->where('slug', $state['slug'])->delete();
        foreach ($state['accounts'] as $key => $id) {
            $db->table('clubora.accounts')->where('id', $id)->where('username', $state['usernames'][$key])->delete();
        }
    });
    if ($fingerprint() !== $state['before']) { throw new LogicException('Rights fingerprint mismatch after cleanup'); }
    unlink($file);
    echo "Fixture removed; original rights unchanged.\n";
} elseif ($mode === 'status') {
    echo "Existing rights unchanged.\n";
    echo json_encode(['invoices' => $db->table('clubora.invoices')->where('tenant_id', $state['tenant'])->get(['id', 'notes']),
        'expenses' => $db->table('clubora.expenses')->where('tenant_id', $state['tenant'])->get(['id', 'notes']),
        'audit_entries' => $db->table('clubora.object_changes')->where('tenant_id', $state['tenant'])->count()], JSON_PRETTY_PRINT)."\n";
} else { throw new LogicException('Unknown mode'); }
