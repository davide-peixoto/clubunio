<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!StageMigrationTarget::allows($db)) throw new LogicException('Unreviewed database');
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.support_tickets (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 requester_id bigint NOT NULL REFERENCES clubora.accounts(id),
 audience text NOT NULL CHECK (audience IN ('club','platform')),
 source_id uuid UNIQUE REFERENCES clubora.support_tickets(id),
 subject varchar(160) NOT NULL, status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','waiting','closed')),
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK ((audience = 'platform') = (source_id IS NOT NULL))
);
CREATE INDEX support_tickets_inbox ON clubora.support_tickets(audience,tenant_id,created_at);
CREATE TABLE clubora.support_posts (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 ticket_id uuid NOT NULL REFERENCES clubora.support_tickets(id),
 author_id bigint NOT NULL REFERENCES clubora.accounts(id),
 internal boolean NOT NULL DEFAULT false, body text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX support_posts_ticket ON clubora.support_posts(ticket_id,id);
CREATE TABLE clubora.support_articles (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_id bigint REFERENCES clubora.tenants(id),
 audience text NOT NULL CHECK (audience IN ('public','club','club_staff','platform')),
 locale text NOT NULL, title varchar(160) NOT NULL, body text NOT NULL,
 published boolean NOT NULL DEFAULT false,
 CHECK ((audience IN ('club','club_staff')) = (tenant_id IS NOT NULL))
);
CREATE INDEX support_articles_visibility ON clubora.support_articles(audience,tenant_id,locale,published);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Support records require a reviewed retention decision.');
    }
};
