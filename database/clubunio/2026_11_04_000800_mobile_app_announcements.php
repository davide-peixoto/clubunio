<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
// Additive public/member banners. No import from legacy/private chat.
return new class extends Migration {
    protected $connection='clubunio';
    public function up(): void {
        $db=DB::connection('clubunio');
        if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.mobile_app_announcements (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 title varchar(255) NOT NULL, body text NOT NULL CHECK(octet_length(body)<=20000),
 audience varchar(16) NOT NULL DEFAULT 'members' CHECK(audience IN ('public','members')),
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL CHECK(ends_at>starts_at),
 active boolean NOT NULL DEFAULT false, revision integer NOT NULL DEFAULT 1 CHECK(revision>0),
 created_by bigint NOT NULL, updated_by bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,created_by) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 FOREIGN KEY(tenant_id,updated_by) REFERENCES clubora.tenant_accounts(tenant_id,account_id)
);
CREATE INDEX mobile_app_announcements_active ON clubora.mobile_app_announcements(tenant_id,starts_at,ends_at) WHERE active;
REVOKE ALL ON clubora.mobile_app_announcements FROM PUBLIC;
SQL);
        foreach(['MaintenanceMode'=>['App-Wartungsmodus','boolean','0'],'MaintenanceMessage'=>['App-Wartungshinweis','string','Die App wird gewartet. Bitte versuche es später erneut.'],
            'RegistrationActive'=>['App-Kontoregistrierung anzeigen','boolean','1']] as $key=>[$label,$type,$value]) {
            $db->insert('INSERT INTO clubora.tenant_settings(tenant_id,area,key,label,value_type,encrypted_value,enabled,is_secret) SELECT id,?,?,?,?,?,true,false FROM clubora.tenants WHERE NOT EXISTS (SELECT 1 FROM clubora.tenant_settings s WHERE s.tenant_id=clubora.tenants.id AND s.area=? AND s.key=?)',
                ['app',$key,$label,$type,Crypt::encryptString($value),'app',$key]);
        }
    }
    public function down(): void { throw new LogicException('Reviewed banner restore required'); }
};
