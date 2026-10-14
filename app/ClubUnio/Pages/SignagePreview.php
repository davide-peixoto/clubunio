<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Services\SignageService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class SignagePreview extends Page
{
    protected string $view = 'clubunio.signage-preview';

    protected static ?string $title = 'Signage-Vorschau';

    public string $date = '';

    public ?string $periodId = null;

    public bool $followToday = true;

    public function updatedDate(): void
    {
        $this->followToday = false;
    }

    public function updatedFollowToday(): void
    {
        $this->refreshDisplay();
    }

    public function refreshDisplay(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->followToday) {
            $this->date = now('Europe/Zurich')->toDateString();
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-tv';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() && (Filament::auth()->user()?->fresh()?->canAccessTenant(Filament::getTenant()) ?? false);
    }

    public function mount(): void
    {
        $this->date = now('Europe/Zurich')->toDateString();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        try {
            $data = app(SignageService::class)->preview(Filament::auth()->user(), Filament::getTenant(), ['date' => $this->date, 'period_id' => $this->periodId]);
        } catch (ValidationException $e) {
            $data = ['competitions' => collect(), 'notices' => collect(), 'fields' => collect(), 'trainings' => collect(), 'selectionError' => 'Bitte ein gültiges Datum und eine verfügbare Trainingsperiode auswählen.'];
        }

        return $data + ['periods' => TrainingSlotResource::choices('period')];
    }
}
