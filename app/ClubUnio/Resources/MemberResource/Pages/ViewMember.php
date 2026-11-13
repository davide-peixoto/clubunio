<?php

namespace App\ClubUnio\Resources\MemberResource\Pages;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\BexioBillingRecipientAction;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\MemberService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
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
        $photos = app(\App\ClubUnio\Services\ProfilePhotoService::class);
        $tenant = Filament::getTenant();
        $actor = Filament::auth()->user();
        $member = $this->getRecord();
        $canEdit = app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'members', 'update');
        $row = $canEdit ? $photos->member($tenant, $member->id) : null;
        $actions = [];
        if ($row?->image_encrypted) {
            $actions[] = \Filament\Actions\Action::make('profilePhoto')->label(__('photo.title'))
                ->icon('heroicon-o-photo')->url(route('clubunio.photo.member', [$tenant->slug, $member->id]), true);
        }
        if ($row?->pending_encrypted && app(\App\ClubUnio\Services\MemberCampaignService::class)->allowed($actor, $tenant)) {
            $actions[] = \Filament\Actions\Action::make('reviewPhoto')->label(__('photo.review'))
                ->icon('heroicon-o-photo')
                ->modalContent(view('clubunio.profile-photo-review', ['url' => route('clubunio.photo.member', [$tenant->slug, $member->id, 'pending' => 1])]))
                ->fillForm(['revision' => $row->revision, 'approve' => false])
                ->schema([
                    \Filament\Forms\Components\Hidden::make('revision')->required()->integer(),
                    \Filament\Forms\Components\Toggle::make('approve')->label(__('photo.approve')),
                ])
                ->action(fn (array $data) => $photos->review($actor, $tenant, $member->id, (int) $data['revision'], (bool) $data['approve']));
        }

        return [...$actions, BexioBillingRecipientAction::make(), EditAction::make()->label('Bearbeiten')->slideOver()
            ->mutateRecordDataUsing(fn (array $data, Member $record) => array_merge($data, ['_version' => MemberService::version($record)]))
            ->using(fn (Member $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)))];
    }
}
