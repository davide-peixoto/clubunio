<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ClubEvent;
use App\ClubUnio\Services\{EventCheckinService, ModulePermissionService};
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{DateTimePicker, Hidden, Toggle};
use Filament\Schemas\Components\Utilities\Get;

final class EventCheckinActions
{
    public static function make(Closure $record): array
    {
        $canManage = static function () use ($record): bool {
            $actor = Filament::auth()->user(); $tenant = Filament::getTenant(); $event = $record();
            return config('clubunio-event-checkin.enabled', false) && $actor && $tenant && $event instanceof ClubEvent
                && (int) $event->tenant_id === (int) $tenant->id
                && app(ModulePermissionService::class)->allows($actor->fresh(), $tenant, 'events', 'update');
        };
        $qr = fn () => app(EventCheckinService::class)->qr(Filament::auth()->user(), Filament::getTenant(), (int) $record()->id);
        $refresh = static function ($livewire): void { $livewire->getRecord()->refresh(); };
        $revision = fn () => [Hidden::make('Revision')->required()];
        return [
            Action::make('configureEventCheckin')->label(__('Configure check-in'))->icon('heroicon-o-qr-code')
                ->visible($canManage)->schema([
                    Hidden::make('Revision')->required(),
                    Toggle::make('Enabled')->label(__('Enable event check-in'))->required()->live(),
                    DateTimePicker::make('OpensAt')->label(__('Check-in opens'))->timezone('Europe/Zurich')->seconds(false)->required(fn (Get $get) => (bool) $get('Enabled')),
                    DateTimePicker::make('ClosesAt')->label(__('Check-in closes'))->timezone('Europe/Zurich')->seconds(false)->required(fn (Get $get) => (bool) $get('Enabled'))->after('OpensAt'),
                ])->fillForm(function () use ($qr) {
                    $data = $qr();
                    return ['Revision' => $data['Revision'], 'Enabled' => $data['Event']['Enabled'],
                        'OpensAt' => $data['Event']['OpensAt'], 'ClosesAt' => $data['Event']['ClosesAt']];
                })->action(function (array $data, $livewire) use ($record, $refresh) {
                    FormMutation::run($livewire, fn () => app(EventCheckinService::class)->configure(
                        Filament::auth()->user(), Filament::getTenant(), (int) $record()->id,
                        ['Enabled' => (bool) $data['Enabled'], 'Revision' => (int) $data['Revision'],
                            'OpensAt' => empty($data['OpensAt']) ? null : CarbonImmutable::parse($data['OpensAt'], 'UTC')->toIso8601String(),
                            'ClosesAt' => empty($data['ClosesAt']) ? null : CarbonImmutable::parse($data['ClosesAt'], 'UTC')->toIso8601String()]));
                    $refresh($livewire);
                }),
            Action::make('printEventCheckin')->label(__('Print QR poster'))->icon('heroicon-o-printer')
                ->visible($canManage)->disabled(fn () => ! $record()->active || ! $record()->checkin_enabled)
                ->tooltip(fn () => ! $record()->active || ! $record()->checkin_enabled ? __('Configure event check-in before printing.') : null)
                ->url(fn () => route('clubunio.event-checkin.print', ['tenant' => Filament::getTenant()->id, 'event' => $record()->id]))->openUrlInNewTab(),
            Action::make('rotateEventCheckin')->label(__('Replace event QR code'))->icon('heroicon-o-arrow-path')
                ->visible($canManage)->requiresConfirmation()->modalDescription(__('Previously printed QR codes will stop working.'))
                ->schema($revision())->fillForm(fn () => ['Revision' => $qr()['Revision']])
                ->action(function (array $data, $livewire) use ($record, $refresh) {
                    FormMutation::run($livewire, fn () => app(EventCheckinService::class)->rotate(Filament::auth()->user(), Filament::getTenant(), (int) $record()->id, (int) $data['Revision']));
                    $refresh($livewire);
                }),
            Action::make('closeEventCheckin')->label(__('Close event check-in'))->icon('heroicon-o-lock-closed')
                ->visible($canManage)->requiresConfirmation()->schema($revision())->fillForm(fn () => ['Revision' => $qr()['Revision']])
                ->action(function (array $data, $livewire) use ($record, $refresh) {
                    FormMutation::run($livewire, fn () => app(EventCheckinService::class)->close(Filament::auth()->user(), Filament::getTenant(), (int) $record()->id, (int) $data['Revision']));
                    $refresh($livewire);
                }),
        ];
    }
}
