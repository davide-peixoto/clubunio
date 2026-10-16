<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\MemberDocumentMaintenanceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class MemberDocumentActions
{
    public static function upload(?int $member = null): Action
    {
        return Action::make('uploadDocument')->label('Dokument hinzufügen')->icon('heroicon-o-arrow-up-tray')->slideOver()->schema([
            ...($member ? [] : [Select::make('member_id')->label('Mitglied')->searchable()->required()
                ->getOptionLabelUsing(fn ($value) => Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')
                    ->select(['id', 'member_number', 'legacy_id', 'company', 'first_name', 'last_name'])->find($value)?->display_name)
                ->getSearchResultsUsing(fn ($search) => Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')
                    ->select(['id', 'member_number', 'legacy_id', 'company', 'first_name', 'last_name'])
                    ->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%'))
                    ->orderBy('last_name')->limit(50)->get()->mapWithKeys(fn ($m) => [$m->id => $m->display_name.' · '.$m->member_number]))]),
            Select::make('document_type_id')->label('Dokumententyp')->required()->searchable()->options(fn () => DocumentType::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('name')->pluck('name', 'id')),
            Toggle::make('portal_visible')->label('Für Mitglied und berechtigte Eltern sichtbar')->default(false)->required(),
            FileUpload::make('file')->label('Datei')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(12288)->required(),
        ])->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberDocumentMaintenanceService::class)->add(Filament::auth()->user(), Filament::getTenant(), $member ?? (int) $data['member_id'], (int) $data['document_type_id'], (bool) $data['portal_visible'], $data['file'])));
    }

    public static function manage(?int $member = null): Action
    {
        return Action::make('manageDocument')->label('Freigabe bearbeiten')->icon('heroicon-o-adjustments-horizontal')->iconButton()->tooltip('Freigabe und Aktivstatus bearbeiten')->slideOver()
            ->fillForm(fn ($record) => $record->only(['revision', 'active', 'portal_visible']))
            ->schema([Hidden::make('revision')->required(), Toggle::make('active')->label('Aktiv')->required(), Toggle::make('portal_visible')->label('Für Mitglied und berechtigte Eltern sichtbar')->required()])
            ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberDocumentMaintenanceService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $member ?? $record->member_id, (int) $data['revision'], (bool) $data['active'], (bool) $data['portal_visible'])));
    }
}
