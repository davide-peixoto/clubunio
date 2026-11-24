<?php

namespace App\ClubUnio\Resources\MemberResource\Pages;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\BexioBillingRecipientAction;
use App\ClubUnio\Resources\ErpNextMemberAction;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\MemberService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
    use \App\ClubUnio\Resources\Concerns\HasModuleOverview;

    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->display_name;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): string
    {
        return 'Stammdaten';
    }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('timeline')->label(trans('workspace.timeline'))->icon('heroicon-o-clock')->slideOver()->modalSubmitAction(false)->modalCancelActionLabel(trans('workspace.close'))
            ->modalContent(fn () => view('clubunio.members.timeline', ['timeline' => app(\App\ClubUnio\Services\MemberTimeline::class)->entries(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id)])), BexioBillingRecipientAction::make(), ErpNextMemberAction::make(), EditAction::make()->label('Bearbeiten')->slideOver()
            ->mutateRecordDataUsing(fn (array $data, Member $record) => array_merge($data, ['_version' => MemberService::version($record)]))
            ->using(fn (Member $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)))];
    }
}
