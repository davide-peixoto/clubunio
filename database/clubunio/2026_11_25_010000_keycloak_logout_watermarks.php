<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || (!StageMigrationTarget::allows($db)
            && !(PHP_SAPI === 'cli' && config('app.url') === 'https://dev.clubunio.ch' && $db->getDatabaseName() === 'clubunio_dev'))) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->unprepared($this->sql());
    }

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubora.keycloak_logout_watermarks (
 account_id bigint PRIMARY KEY REFERENCES clubora.accounts(id),
 issuer varchar(512) NOT NULL, subject varchar(255) NOT NULL,
 cutoff bigint NOT NULL CHECK(cutoff >= 0)
);
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Preserve revocation floors; never restore revoked sessions by deleting logout state.');
    }
};
