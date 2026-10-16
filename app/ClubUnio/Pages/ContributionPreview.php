<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Season;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\ContributionBatchService;
use App\ClubUnio\Services\ContributionInvoiceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class ContributionPreview extends Page
{
    protected string $view = 'clubunio.contribution-preview';

    protected static ?string $title = 'Beitragsberechnung';

    #[Locked]
    public array $calculation = [];

    #[Locked]
    public array $batch = [];

    #[Locked]
    public array $batchResults = [];

    public function nextBatch(): void
    {
        abort_unless(static::canAccess() && ! empty($this->batch['next']), 403);
        $this->batch = app(ContributionBatchService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->batch['season_id'], $this->batch['next']);
        $this->batchResults = [];
    }

    public function createBatch(): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create'), 403);
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('invoices', 'create'), 403);
        abort_unless(static::canAccess() && $this->batch && ! $this->batchResults, 403);
        $this->batchResults = app(ContributionBatchService::class)->create(Filament::auth()->user(), Filament::getTenant(), $this->batch);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-calculator';
    }

    public static function canAccess(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('contributions');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('calculate')->label('Beitrag berechnen')->modalSubmitActionLabel('Berechnen')->icon('heroicon-o-calculator')->schema([
            MemberResource::memberSelect('member_id')->required(),
            Select::make('season_id')->label('Saison')->required()->searchable()->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderByDesc('name')->pluck('name', 'id')),
        ])->action(function (array $data) {
            $this->calculation = [];
            $this->calculation = app(ContributionInvoiceService::class)->preview(Filament::auth()->user(), Filament::getTenant(), (int) $data['member_id'], (int) $data['season_id']);
        }), Action::make('seasonRun')->label('Saisonlauf prüfen')->icon('heroicon-o-queue-list')->modalSubmitActionLabel('Vorschau laden')
            ->schema([Select::make('season_id')->label('Saison')->required()->searchable()->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderByDesc('name')->pluck('name', 'id'))])
            ->action(function (array $data) {
                $this->batchResults = [];
                $this->batch = app(ContributionBatchService::class)->preview(Filament::auth()->user(), Filament::getTenant(), (int) $data['season_id']);
            })];
    }

    public function createDraft(): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create'), 403);
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('invoices', 'create'), 403);
        abort_unless(static::canAccess() && $this->calculation, 403);
        $invoice = app(ContributionInvoiceService::class)->create(Filament::auth()->user(), Filament::getTenant(),
            $this->calculation['member_id'], $this->calculation['season_id'], $this->calculation['fingerprint']);
        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
    }
}
