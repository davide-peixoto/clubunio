<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\MaterialReceiptService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MaterialReceiptResource extends CatalogResource
{
    protected static ?string $model = MaterialReceipt::class;

    protected static ?string $modelLabel = 'Materialübergabe';

    protected static ?string $pluralModelLabel = 'Materialübergaben';

    protected static ?string $tenantRelationshipName = 'materialReceipts';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function fields(?int $member = null): array
    {
        return [Hidden::make('revision')->default(0),
            Select::make('member_id')->label('Mitglied')->required()->default($member)->disabled($member !== null)->dehydrated()
                ->searchable()->getSearchResultsUsing(fn (string $search) => Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')
                ->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%'))
                ->orderBy('last_name')->limit(50)->get()->pluck('display_name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => Member::where('tenant_id', Filament::getTenant()->id)->find($value)?->display_name),
            TextInput::make('title')->label('Bezeichnung')->required()->maxLength(200),
            DatePicker::make('handover_on')->label('Übergabedatum')->default(now('Europe/Zurich')->toDateString())->required(),
            Repeater::make('items')->label('Gegenstände')->minItems(1)->maxItems(100)->defaultItems(1)->schema([
                TextInput::make('description')->label('Gegenstand')->required()->maxLength(300),
                TextInput::make('quantity')->label('Anzahl')->integer()->minValue(1)->maxValue(10000)->default(1)->required(),
                TextInput::make('reference')->label('Nummer / Grösse')->maxLength(120),
                Textarea::make('condition')->label('Zustand')->maxLength(500),
            ])->columns(2)->addActionLabel('Gegenstand hinzufügen'),
            Textarea::make('note')->label('Bemerkung')->maxLength(3000),
        ];
    }

    public static function createReceipt(?int $member = null): Action
    {
        return Action::make('newReceipt')->label('Materialübergabe erfassen')->icon('heroicon-o-plus')->slideOver()->modalSubmitActionLabel('Entwurf speichern')->schema(self::fields($member))
            ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(MaterialReceiptService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $member ? array_replace($data, ['member_id' => $member]) : $data)));
    }

    public static function table(Table $table): Table
    {
        $tenant = fn () => Filament::getTenant();
        $service = fn () => app(MaterialReceiptService::class);

        return $table->columns([
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($q, $search) => $q->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('last_name', 'ilike', '%'.$search.'%')->orWhere('first_name', 'ilike', '%'.$search.'%')))),
            TextColumn::make('title')->label('Bezeichnung')->searchable()->wrap(),
            TextColumn::make('handover_on')->label('Übergabe')->date('d.m.Y')->sortable(),
            TextColumn::make('state')->label('Status')->formatStateUsing(fn ($state) => $service()->states($tenant())[$state] ?? $state)->badge()
                ->color(fn ($state) => StatusAppearance::palette($service()->states($tenant())[$state] ?? $state)),
            TextColumn::make('confirmation_name')->label('Bestätigt durch'),
            TextColumn::make('confirmed_at')->label('Bestätigt am')->dateTime('d.m.Y H:i', 'Europe/Zurich'),
        ])->filters([SelectFilter::make('state')->label('Status')->options(fn () => $service()->states($tenant()))])
            ->headerActions([self::createReceipt()])->recordActions([
                Action::make('editReceipt')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Entwurf bearbeiten')->slideOver()
                    ->visible(fn ($record) => $record->state === 'draft')->fillForm(fn ($record) => $record->only(['revision', 'member_id', 'title', 'handover_on', 'items', 'note']))->schema(self::fields())
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => $service()->save(Filament::auth()->user(), $tenant(), $record->id, $data))),
                Action::make('issueReceipt')->label('Zur Bestätigung bereitstellen')->icon('heroicon-o-paper-airplane')->iconButton()->tooltip('Zur Bestätigung bereitstellen')
                    ->visible(fn ($record) => $record->state === 'draft')->requiresConfirmation()->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => $service()->transition(Filament::auth()->user(), $tenant(), $record->id, (int) $data['revision'], 'issue'))),
                Action::make('receiptPdf')->label('Quittung')->icon('heroicon-o-document-text')->iconButton()->tooltip('PDF-Quittung öffnen')->visible(fn ($record) => (bool) $record->snapshot)
                    ->url(fn ($record) => route('clubunio.material.pdf', [$tenant()->slug, $record->id]))->openUrlInNewTab(),
                Action::make('cancelReceipt')->label('Stornieren')->icon('heroicon-o-x-circle')->iconButton()->tooltip('Übergabe stornieren')->requiresConfirmation()
                    ->visible(fn ($record) => in_array($record->state, ['draft', 'issued'], true))->fillForm(fn ($record) => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision'), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => $service()->transition(Filament::auth()->user(), $tenant(), $record->id, (int) $data['revision'], 'cancel', $data))),
            ])->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => MaterialReceiptResource\Pages\ListMaterialReceipts::route('/')];
    }
}
