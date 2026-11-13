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
ALTER TABLE clubora.support_tickets ADD assignee_id bigint REFERENCES clubora.accounts(id);
ALTER TABLE clubora.support_articles ADD video_url varchar(2048);
CREATE TABLE clubora.support_attachments (
 id uuid PRIMARY KEY, post_id bigint NOT NULL REFERENCES clubora.support_posts(id),
 filename varchar(255) NOT NULL, mime varchar(60) NOT NULL, contents text NOT NULL
);
CREATE INDEX support_attachments_post ON clubora.support_attachments(post_id);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Support records require a reviewed retention decision.');
    }
};
