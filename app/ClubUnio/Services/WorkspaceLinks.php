<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Resources as R;
use App\ClubUnio\Resources\{MemberResource, InvoiceResource, ExpenseResource, RegistrationResource, TrainingSlotResource, CompetitionResource};
use App\ClubUnio\Pages\{TeamPlanning, BusReservations, PitchPlanning, Chats, SocialStudio, MyClub, ApprovalCenter, PlanningConflicts};

class WorkspaceLinks
{
    public const RECORDS = ['members' => MemberResource::class, 'invoices' => InvoiceResource::class, 'expenses' => ExpenseResource::class];
    public const LISTS = self::RECORDS + ['registrations' => RegistrationResource::class, 'training-slots' => TrainingSlotResource::class, 'competitions' => CompetitionResource::class,
        'customer-credits'=>R\CustomerCreditResource::class,'credit-notes'=>R\CreditNoteResource::class,'refunds'=>R\RefundResource::class,
        'member-reports'=>R\MemberReportResource::class,'open-invoices'=>R\OpenInvoiceResource::class,'player-reports'=>R\PlayerReportResource::class,
        'staff-reports'=>R\StaffReportResource::class,'payment-reports'=>R\PaymentReportResource::class,'unassigned-members'=>R\UnassignedMemberResource::class,
        'passive-members'=>R\PassiveMemberResource::class,'signage-notices'=>R\SignageNoticeResource::class,'family-reports'=>R\FamilyReportResource::class,
        'bank-account-reports'=>R\BankAccountReportResource::class,'football-clubs'=>R\FootballClubResource::class,'key-reports'=>R\KeyReportResource::class,
        'spots-recipients'=>R\SpotsRecipientResource::class];

    public function available(): array
    {
        $links = [];
        foreach (self::LISTS as $key => $resource) {
            if ($resource::canViewAny()) $links[$key] = ['label' => $resource::getPluralModelLabel(), 'url' => $resource::getUrl(), 'icon' => 'heroicon-o-rectangle-stack'];
        }
        foreach (['planning' => TeamPlanning::class, 'bus' => BusReservations::class, 'pitch' => PitchPlanning::class,
            'notifications'=>\App\ClubUnio\Pages\NotificationCenter::class, 'search'=>\App\ClubUnio\Pages\ObjectSearch::class, 'tasks'=>\App\ClubUnio\Pages\TeamTasks::class, 'capture'=>\App\ClubUnio\Pages\GuidedCapture::class, 'chats' => Chats::class, 'social' => SocialStudio::class, 'approvals' => ApprovalCenter::class, 'conflicts' => PlanningConflicts::class] as $key => $page) {
            if ($page::canAccess()) $links[$key] = ['label' => $page::getNavigationLabel(), 'url' => $page::getUrl(), 'icon' => $page::getNavigationIcon()];
        }
        return $links;
    }

    public function quickActions(): array
    {
        $links = [];
        if (ExpenseResource::canViewAny() && \App\ClubUnio\Resources\ProfileUi::allows('expenses','create')) $links['expense'] = ['label' => trans('workspace.new_expense'), 'url' => ExpenseResource::getUrl('index',['tableAction'=>'newExpense']), 'icon' => 'heroicon-o-receipt-percent'];
        if (TeamPlanning::canAccess() && \App\ClubUnio\Resources\ProfileUi::allows('planning', 'create')) {
            $tenant = \Filament\Facades\Filament::getTenant();
            $links['event'] = ['label' => trans('workspace.new_event'), 'url' => route('clubunio.planning.create', $tenant->slug), 'icon' => 'heroicon-o-calendar-days'];
        }
        if (ApprovalCenter::canAccess()) $links['approvals'] = ['label' => trans('workspace.approvals'), 'url' => ApprovalCenter::getUrl(), 'icon' => 'heroicon-o-check-badge'];
        if (Chats::canAccess()) $links['chats'] = ['label' => trans('workspace.chats'), 'url' => Chats::getUrl(), 'icon' => 'heroicon-o-chat-bubble-left-right'];
        return array_slice($links, 0, 3, true);
    }
}
