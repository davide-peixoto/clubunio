<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void
    {
        $db = DB::connection('clubunio');
        $expected = ['https://dev.clubunio.ch' => 'clubunio_dev', 'https://stage.clubunio.com' => 'clubunio_stage', 'https://app.clubunio.com' => 'clubunio_prod'][config('app.url')] ?? null;
        if (PHP_SAPI !== 'cli' || ! $expected || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $expected) {
            throw new LogicException('Reviewed parent-access target required');
        }
        $db->unprepared($this->sql());
    }
    public function sql(): string
    {
        return <<<'SQL'
ALTER TABLE clubora.account_member_links
 ADD COLUMN adult_consent_at timestamptz,
 ADD COLUMN adult_consent_by bigint REFERENCES clubora.accounts(id),
 ADD COLUMN adult_consent_source varchar(16),
 ADD CONSTRAINT account_member_links_adult_consent CHECK (
  (adult_consent_at IS NULL AND adult_consent_by IS NULL AND adult_consent_source IS NULL)
  OR (kind='parent' AND state='approved' AND adult_consent_at IS NOT NULL AND adult_consent_by IS NOT NULL AND adult_consent_source IS NOT NULL AND adult_consent_source IN ('member','office'))
 );
CREATE TABLE clubora.account_parent_email_proofs (
 account_id bigint PRIMARY KEY REFERENCES clubora.accounts(id),
 email varchar(254) NOT NULL, verified_at timestamptz NOT NULL,
 issuer_hash char(64) NOT NULL,
 CHECK(email=lower(btrim(email)))
);
CREATE INDEX account_parent_email_proofs_email ON clubora.account_parent_email_proofs(email);
CREATE INDEX members_parent_mother_email ON clubora.members(lower(btrim(mother_email)),tenant_id) WHERE mother_email IS NOT NULL AND archived_at IS NULL;
CREATE INDEX members_parent_father_email ON clubora.members(lower(btrim(father_email)),tenant_id) WHERE father_email IS NOT NULL AND archived_at IS NULL;
REVOKE ALL ON clubora.account_parent_email_proofs FROM PUBLIC;
SQL;
    }
    public function down(): void { throw new LogicException('Parent consent requires a reviewed rollback.'); }
};
