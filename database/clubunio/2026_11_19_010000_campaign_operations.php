<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) throw new LogicException('Unexpected campaign target');
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.campaigns ADD COLUMN workflow_version integer NOT NULL DEFAULT 1,
 ADD COLUMN target_id bigint, ADD COLUMN reminder_days integer NOT NULL DEFAULT 7 CHECK(reminder_days BETWEEN 1 AND 30),
 ADD COLUMN auto_reminders boolean NOT NULL DEFAULT false;
ALTER TABLE clubunio.campaign_recipients
 ADD COLUMN locale varchar(2) NOT NULL DEFAULT 'de' CHECK(locale IN ('de','en')),
 ADD COLUMN contact_encrypted text, ADD COLUMN contact_baseline char(64),
 ADD COLUMN verified_by bigint, ADD COLUMN verified_at timestamptz,
 ADD COLUMN first_opened_at timestamptz, ADD COLUMN printed_at timestamptz,
 ADD COLUMN response_encrypted text, ADD COLUMN response_reviewed_at timestamptz,
 ADD COLUMN response_reviewed_by bigint, ADD COLUMN response_object_id bigint,
 ADD COLUMN response_disposition varchar(12) CHECK(response_disposition IN ('approved','rejected')),
 ADD FOREIGN KEY(tenant_id, verified_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id),
 ADD FOREIGN KEY(tenant_id, response_reviewed_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id);
CREATE TABLE clubunio.campaign_templates (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), name varchar(160) NOT NULL,
 kind varchar(40) NOT NULL, content jsonb NOT NULL, revision integer NOT NULL DEFAULT 1,
 active boolean NOT NULL DEFAULT true, created_by bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,name),
 FOREIGN KEY(tenant_id,created_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id)
);
CREATE TABLE clubunio.campaign_dispatches (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, campaign_id bigint NOT NULL, recipient_id bigint NOT NULL,
 sequence integer NOT NULL CHECK(sequence BETWEEN 0 AND 3),
 state varchar(16) NOT NULL CHECK(state IN ('queued','sending','accepted','delivered','bounced','failed','attention','cancelled')),
 address_hash char(64) NOT NULL, contact_hash char(64) NOT NULL, actor_id bigint NOT NULL,
 attempts integer NOT NULL DEFAULT 0, due_at timestamptz NOT NULL DEFAULT now(),
 attempted_at timestamptz, accepted_at timestamptz, finished_at timestamptz,
 outcome varchar(40), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,recipient_id,sequence),
 FOREIGN KEY(tenant_id,campaign_id) REFERENCES clubunio.campaigns(tenant_id,id),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES clubunio.tenant_accounts(tenant_id,account_id)
);
ALTER TABLE clubunio.campaign_recipients ADD CONSTRAINT campaign_recipient_tenant_id UNIQUE(tenant_id,id);
ALTER TABLE clubunio.campaign_dispatches ADD FOREIGN KEY(tenant_id,recipient_id) REFERENCES clubunio.campaign_recipients(tenant_id,id);
CREATE INDEX campaign_dispatch_due ON clubunio.campaign_dispatches(due_at,id) WHERE state='queued';
CREATE INDEX campaign_dispatch_address ON clubunio.campaign_dispatches(tenant_id,address_hash,state);
CREATE TABLE clubunio.campaign_delivery_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL,
 dispatch_id uuid NOT NULL, source_key varchar(100) NOT NULL, state varchar(16) NOT NULL, details_encrypted text,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,source_key),
 FOREIGN KEY(tenant_id,dispatch_id) REFERENCES clubunio.campaign_dispatches(tenant_id,id)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Retain campaign delivery and response evidence; reviewed restore required');
    }
};
