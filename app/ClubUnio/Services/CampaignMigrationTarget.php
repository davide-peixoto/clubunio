<?php

namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;

final class CampaignMigrationTarget
{
    public static function allows(Connection $db): bool
    {
        return StageMigrationTarget::allows($db) || (PHP_SAPI === 'cli'
            && config('app.url') === 'https://dev.clubunio.ch'
            && $db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'clubunio_dev'
            && $db->selectOne('select current_database() as name')->name === 'clubunio_dev');
    }
}
