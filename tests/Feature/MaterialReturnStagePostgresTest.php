<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MaterialReturnService, StageMigrationTarget};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MaterialReturnStagePostgresTest extends TestCase
{
    public function test_migration_and_partial_returns_with_isolated_clubs(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to the isolated PostgreSQL test database.');
        }

        $db = DB::connection('clubunio');
        StageMigrationTarget::authorize($db, 'clubunio_stage_material_test');
        $db->beginTransaction();

        try {
            $migration = '2026_11_16_003030_material_returns';
            $this->artisan('migrate', [
                '--database' => 'clubunio',
                '--path' => "database/clubunio/$migration.php",
                '--force' => true,
            ])->assertSuccessful();
            $this->assertSame(1, $db->table('clubunio.migrations')->where('migration', $migration)->count());

            $makeClub = function (string $suffix) use ($db): array {
                $tenant = Tenant::create(['slug' => 'material-test-'.$suffix, 'name' => 'Material test']);
                $actor = (new Account)->forceFill([
                    'username' => 'returns-'.$suffix.'@example.invalid', 'display_name' => 'Test recorder',
                    'password' => bcrypt(Str::random(24)), 'active' => true, 'api_only' => false,
                ]);
                $actor->save();
                $db->table('clubunio.tenant_accounts')->insert([
                    'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin',
                ]);

                return [$tenant, $actor];
            };
            [$tenant, $actor] = $makeClub('a');
            [$foreign, $other] = $makeClub('b');
            $member = $db->table('clubunio.members')->insertGetId([
                'tenant_id' => $tenant->id, 'first_name' => 'Test', 'last_name' => 'Member',
            ]);
            $items = [['description' => 'Jersey', 'quantity' => 5]];
            $snapshot = json_encode(['member' => 'Test Member', 'items' => $items], JSON_THROW_ON_ERROR);
            $db->table('clubunio.material_receipt_statuses')->insert([
                'tenant_id' => $tenant->id, 'code' => 'issued', 'name' => 'Issued',
            ]);
            $receipt = $db->table('clubunio.material_receipts')->insertGetId([
                'tenant_id' => $tenant->id, 'member_id' => $member, 'title' => 'Test receipt',
                'handover_on' => '2026-11-10', 'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'created_by' => $actor->id, 'issued_by' => $actor->id, 'issued_at' => '2026-11-10 12:00:00+01',
                'state' => 'issued', 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', $snapshot),
            ]);

            $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-17 12:00', 'Europe/Zurich'));
            $service = app(MaterialReturnService::class);
            $service->deadline($actor, $tenant, $receipt, 1, '2026-11-16');
            $book = fn (int $quantity, string $condition, ?string $key = null) => $service->book(
                $actor, $tenant, $receipt, 1, [
                    'quantity' => $quantity, 'returned_on' => '2026-11-17', 'condition' => $condition,
                ], $key ?? (string) Str::uuid()
            );
            $key = (string) Str::uuid();
            $book(2, 'Intact', $key);
            $book(2, 'Intact', $key);
            $book(1, 'Damaged');
            $open = $service->positions($actor, $tenant, $member, true, false)->items();
            $this->assertCount(1, $open);
            $this->assertSame(2, (int) $open[0]->issued - (int) $open[0]->returned);
            $this->assertSame(0, $service->positions($other, $foreign, null, false, false)->total());
            try {
                $service->deadline($other, $foreign, $receipt, 1, '2026-11-16');
                $this->fail('A different club could change the deadline.');
            } catch (ModelNotFoundException) {
                $this->assertCount(0, $service->history($other, $foreign, $receipt, 1));
            }
            try {
                $book(3, 'Intact');
                $this->fail('Overbooking was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }
            $book(2, 'Intact');
            $this->assertSame(0, $service->positions($actor, $tenant, null, false, false)->total());
            $this->assertSame(1, $service->positions($actor, $tenant, null, false, true)->total());
            $this->assertCount(3, $service->history($actor, $tenant, $receipt, 1));
            $this->assertSame(hash('sha256', $snapshot), $db->table('clubunio.material_receipts')->where('id', $receipt)->value('snapshot_hash'));
        } finally {
            $this->travelBack();
            $db->rollBack();
        }
    }
}
