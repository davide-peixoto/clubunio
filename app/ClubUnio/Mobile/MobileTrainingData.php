<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Validator};

final class MobileTrainingData
{
    public function plan(Account $actor, Tenant $tenant, object $row): array
    {
        $service = app(MobileTrainingPlanService::class);
        $editor = DB::connection('clubunio')->table('clubora.object_changes as changes')->join('clubora.accounts as account', 'account.id', '=', 'changes.actor_id')
            ->where('changes.tenant_id', $tenant->id)->where('changes.object_type', 'training_plan')->where('changes.object_id', $row->id)
            ->orderByDesc('changes.id')->value('account.display_name');
        return array_merge($this->content($row->plan), [
            'id' => (int) $row->id, 'revision' => (int) $row->revision,
            'teamName' => $row->input['teamName'], 'playerCount' => (int) $row->input['playerCount'],
            'durationMinutes' => (int) $row->input['durationMinutes'], 'generatedAt' => $row->created_at,
            'updatedBy' => $editor ?? '',
            'whatsappSummary' => $row->input['teamName'].' · '.$row->plan['title']."\n".$row->plan['focus']."\n".
                implode("\n", array_map(fn ($exercise) => $exercise['durationMinutes'].' Min. '.$exercise['title'], $row->plan['exercises'])),
            'sharedTeamSaisonId' => $service->sharedTeam($tenant, $row),
            'canEdit' => $service->can($actor, $tenant, 'update', $row), 'canShare' => $service->can($actor, $tenant, 'send', $row),
            'canReview' => $service->can($actor, $tenant, 'update', $row) && $service->can($actor, $tenant, 'approve', $row) && app(MobileTrainingWork::class)->available($tenant),
            'canDownload' => $service->can($actor, $tenant, 'download'),
            'hasPdf' => $service->can($actor, $tenant, 'download'),
        ]);
    }

    public function content(array $plan): array
    {
        foreach ($plan['exercises'] as &$exercise) {
            if ($exercise['diagramStale'] ?? false) {
                $exercise['diagramDescription'] = ''; $exercise['diagram'] = null;
            } else {
                $svg = app(\App\ClubUnio\Services\TrainingPlanContent::class)->diagram($exercise);
                $exercise['diagram'] = ['description' => $exercise['diagramDescription'], 'svg' => base64_decode(substr($svg, strpos($svg, ',') + 1), true)];
            }
        }
        return $plan;
    }

    /** Preserve all non-editable canonical exercise data from the displayed revision. */
    public function changes(object $row, array $input): array
    {
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'focus' => 'required|string|max:1000',
            'playerCount' => 'required|integer|min:4|max:30', 'exercises' => 'required|array|list|min:3|max:12',
            'exercises.*.title' => 'required|string|max:160', 'exercises.*.durationMinutes' => 'required|integer|min:1|max:120',
            'exercises.*.setup' => 'required|string|max:3000', 'exercises.*.flow' => 'required|string|max:5000'])->validate();
        abort_unless(count($data['exercises']) === count($row->plan['exercises']), 422, 'Übungsanzahl wurde verändert.');
        $draft = $row->plan;
        $draft['title'] = $data['title']; $draft['focus'] = $data['focus'];
        foreach ($data['exercises'] as $i => $exercise) {
            $exercise = array_intersect_key($exercise, array_flip(['title','durationMinutes','setup','flow']));
            $original = $draft['exercises'][$i];
            $draft['exercises'][$i] = array_replace($original, $exercise);
            if ($exercise['setup'] !== $original['setup'] || $exercise['flow'] !== $original['flow'] || (int) $row->input['playerCount'] !== $data['playerCount']) {
                $draft['exercises'][$i]['diagramStale'] = true;
            }
        }
        return [$draft, ['playerCount' => $data['playerCount']]];
    }
}
