<?php
namespace App\Console\Commands;

use App\ClubUnio\Mobile\MobilePushOutbox;
use Illuminate\Console\Command;

final class DeliverMobilePush extends Command
{
    protected $signature = 'clubunio:deliver-mobile-push {--dispatch : Explicitly permit configured external delivery} {--limit=20} {--outbox= : Deliver only this existing outbox UUID without collecting other work} {--watch= : Poll committed work for up to this many seconds (1-3600)}';
    protected $description = 'Deliver bounded personal push outbox work; closed unless operator explicitly enables delivery.';
    public function handle(): int
    {
        if (! $this->option('dispatch') || ! config('clubunio-mobile-push.enabled', false) || ! config('clubunio-mobile-push.delivery_enabled', false)) {
            $this->error('Personal push delivery is closed.'); return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) { $this->error('Limit must be between1 and100.'); return self::FAILURE; }
        $watch = $this->option('watch');
        if ($watch !== null && (($watch = filter_var($watch, FILTER_VALIDATE_INT)) === false || $watch < 1 || $watch > 3600)) {
            $this->error('Watch duration must be between1 and3600 seconds.'); return self::FAILURE;
        }
        $outbox = $this->option('outbox');
        if ($outbox !== null) {
            if ($watch !== null) { $this->error('Selected delivery cannot watch other work.'); return self::FAILURE; }
            if (! \Illuminate\Support\Str::isUuid($outbox)) {
                $this->error('A valid push outbox UUID is required.'); return self::FAILURE;
            }
            $processed = app(MobilePushOutbox::class)->deliverOne($outbox) ? 1 : 0;
            $this->info($processed.' selected outbox record processed; provider acceptance is not device delivery.');
            return self::SUCCESS;
        }
        if ($watch === null) { $this->cycle($limit, true); return self::SUCCESS; }
        $this->info('Watching committed mobile push work.');
        $until = hrtime(true) + $watch * 1_000_000_000;
        $nextCollection = 0;
        $previousRequest = request();
        try { do {
            // A resident worker must not retain HTTP/season/policy facts between cycles.
            app()->instance('request', \Illuminate\Http\Request::create(config('app.url'), 'GET'));
            $collect = hrtime(true) >= $nextCollection;
            $this->cycle($limit, $collect, true);
            if ($collect) $nextCollection = hrtime(true) + 2_000_000_000;
            usleep(250_000);
        } while (hrtime(true) < $until); }
        finally { app()->instance('request', $previousRequest); }
        return self::SUCCESS;
    }

    private function cycle(int $limit, bool $collect, bool $quiet = false): void
    {
        $processed = 0;
        $fanouts = 0;
        while ($processed < $limit && app(MobilePushOutbox::class)->deliverOne()) $processed++;
        while ($fanouts < $limit && $processed < $limit && app(\App\ClubUnio\Mobile\ChatPushPublications::class)->processOne()) {
            $fanouts++;
            // Send this fanout immediately, before calculating all later publications.
            while ($processed < $limit && app(MobilePushOutbox::class)->deliverOne()) $processed++;
        }
        // Chat delivery never waits behind periodic legacy source collection.
        if ($collect) {
            app(\App\ClubUnio\Mobile\MobilePushSources::class)->existingNotifications();
            app(\App\ClubUnio\Mobile\MobilePushSources::class)->challengeResets();
            app(\App\ClubUnio\Mobile\MobileAdminNoticeService::class)->pending();
            while ($processed < $limit && app(MobilePushOutbox::class)->deliverOne()) $processed++;
        }
        if (!$quiet || $processed || $fanouts) $this->info($processed.' outbox records processed; provider acceptance is not device delivery.');
    }
}
