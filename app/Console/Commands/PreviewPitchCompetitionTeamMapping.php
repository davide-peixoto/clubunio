<?php

namespace App\Console\Commands;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\PitchCompetitionTeamMapping;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PreviewPitchCompetitionTeamMapping extends Command
{
    protected $signature = 'clubunio:pitch-team-mapping {--tenant=} {--actor=} {--from=} {--to=} {--locale=de} {--json} {--apply} {--confirm=}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(trans('pitch_mapping.command_description'));
    }

    public function handle(PitchCompetitionTeamMapping $mapping): int
    {
        $locale = $this->option('locale');
        $tenantId = filter_var($this->option('tenant'), FILTER_VALIDATE_INT);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        if (! array_key_exists($locale, config('clubunio-languages.enabled', [])) || ! $tenantId || $tenantId < 1 || ! $actorId || $actorId < 1) {
            $this->error(trans('pitch_mapping.invalid_scope'));
            return self::INVALID;
        }
        $previousLocale = app()->getLocale();
        app()->setLocale($locale);
        try {
            if (($this->option('apply') && ! preg_match('/^[a-f0-9]{64}$/D', (string) $this->option('confirm')))
                || (! $this->option('apply') && $this->option('confirm') !== null)) {
                $this->error(trans('pitch_mapping.confirmation_required'));
                return self::INVALID;
            }
            $tenant = Tenant::find($tenantId);
            $actor = Account::find($actorId);
            if (! $tenant || ! $actor) {
                $this->error(trans('pitch_mapping.invalid_scope'));
                return self::INVALID;
            }
            $arguments = [$actor, $tenant, (string) $this->option('from'), (string) $this->option('to')];
            $report = $this->option('apply')
                ? $mapping->apply(...[...$arguments, $this->option('confirm'), true])
                : $mapping->preview(...$arguments);
            foreach ($report['entries'] as &$entry) $entry['message'] = trans('pitch_mapping.reasons.'.$entry['reason']);
            unset($entry);
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                $this->info(trans('pitch_mapping.summary', $report['summary']));
                $this->table(trans('pitch_mapping.headers'), array_map(fn ($entry) => [
                    $entry['competition_id'], $entry['match_id'], $entry['date'], implode(', ', $entry['team_season_ids']), $entry['message'],
                ], $report['entries']));
                $this->line(trans('pitch_mapping.fingerprint', ['fingerprint' => $report['fingerprint']]));
                if (isset($report['applied'])) $this->info(trans('pitch_mapping.applied', ['count' => $report['applied']]));
            }
            return $report['summary']['error'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) foreach ($messages as $message) $this->error($message);
            return self::INVALID;
        } catch (HttpExceptionInterface $error) {
            $this->error($error->getMessage() ?: trans('pitch_mapping.denied'));
            return self::FAILURE;
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
