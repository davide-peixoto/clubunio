<?php
namespace App\Console\Commands;

use App\ClubUnio\Mobile\MobilePushOutbox;
use Illuminate\Console\Command;

final class DeliverMobilePush extends Command
{
    protected $signature = 'clubunio:deliver-mobile-push {--dispatch : Explicitly permit configured external delivery} {--limit=20} {--outbox= : Deliver only this existing outbox UUID without collecting other work}';
    protected $description = 'Deliver bounded personal push outbox work; closed unless operator explicitly enables delivery.';
    public function handle(): int
    {
        if (! $this->option('dispatch') || ! config('clubunio-mobile-push.enabled', false) || ! config('clubunio-mobile-push.delivery_enabled', false)) {
            $this->error('Personal push delivery is closed.'); return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) { $this->error('Limit must be between1 and100.'); return self::FAILURE; }
        $outbox = $this->option('outbox');
        if ($outbox !== null) {
            if (! \Illuminate\Support\Str::isUuid($outbox)) {
                $this->error('A valid push outbox UUID is required.'); return self::FAILURE;
            }
            $processed = app(MobilePushOutbox::class)->deliverOne($outbox) ? 1 : 0;
            $this->info($processed.' selected outbox record processed; provider acceptance is not device delivery.');
            return self::SUCCESS;
        }
        $processed = 0;
        app(\App\ClubUnio\Mobile\MobilePushSources::class)->existingNotifications();
        app(\App\ClubUnio\Mobile\MobilePushSources::class)->challengeResets();
        app(\App\ClubUnio\Mobile\MobileAdminNoticeService::class)->pending();
        $fanouts = 0;
        while ($fanouts < $limit && app(\App\ClubUnio\Mobile\ChatPushPublications::class)->processOne()) $fanouts++;
        while ($processed < $limit && app(MobilePushOutbox::class)->deliverOne()) $processed++;
        $this->info($processed.' outbox records processed; provider acceptance is not device delivery.');
        return self::SUCCESS;
    }
}
