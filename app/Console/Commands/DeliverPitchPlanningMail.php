<?php

namespace App\Console\Commands;

use App\ClubUnio\Services\PitchPlanningNotificationService;
use App\ClubUnio\Services\TestMailGuard;
use Illuminate\Console\Command;

final class DeliverPitchPlanningMail extends Command
{
    protected $signature = 'clubunio:deliver-pitch-mail {--dispatch} {--tenant=} {--event=} {--limit=20}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(trans('pitch_notifications.command_description'));
    }

    public function handle(): int
    {
        if (! $this->option('dispatch') || ! app()->environment(['local', 'testing', 'staging'])
            || ! config('clubunio-integrations.test_mail_enabled', false)
            || ! config('clubunio-integrations.pitch_planning_test_mail_enabled', false)) {
            $this->error(trans('pitch_notifications.closed'));

            return self::FAILURE;
        }
        $tenant = filter_var($this->option('tenant'), FILTER_VALIDATE_INT);
        $event = $this->option('event') === null ? null : filter_var($this->option('event'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($tenant === false || $tenant < 1 || ($event !== null && ($event === false || $event < 1))
            || $limit === false || $limit < 1 || $limit > 50) {
            $this->error(trans('pitch_notifications.invalid_scope'));

            return self::INVALID;
        }
        $result = app(PitchPlanningNotificationService::class)->dispatch($tenant, $limit, $event);
        $this->info(trans('pitch_notifications.result', ['recipient' => TestMailGuard::RECIPIENT,
            'accepted' => $result['accepted'], 'failed' => $result['failed']]));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
