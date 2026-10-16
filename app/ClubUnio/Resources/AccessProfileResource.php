<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\AccountAdministrationService;
use App\ClubUnio\Services\PermissionCatalog;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccessProfileResource extends CatalogResource
{
    protected static ?string $model = AccessProfile::class;
    protected static ?string $modelLabel = 'Berechtigungsprofil';
    protected static ?string $pluralModelLabel = 'Rollen & Berechtigungen';
    protected static ?string $slug = 'access-profiles';
    protected static ?string $tenantRelationshipName = 'accessProfiles';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    public static function canViewAny(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $actor && $tenant && app(AccountAdministrationService::class)->allowed($actor, $tenant);
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }

    public static function fields(): array
    {
        $fields = [
            TextInput::make('name')->label('Profilname')->required()->maxLength(120),
            Textarea::make('description')->label('Beschreibung')->maxLength(2000),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
            Select::make('preset')->label('Vorlage')->options(['empty' => 'Ohne Rechte', 'read' => 'Nur Lesen'])
                ->live()->dehydrated(false)->afterStateUpdated(function (?string $state, Set $set) {
                    if (! in_array($state, ['empty', 'read'], true)) {
                        return;
                    }
                    foreach (PermissionCatalog::MODULES as $module => $label) {
                        $set('modules.'.$module, $state === 'read' ? ['view'] : []);
                    }
                }),
        ];
        foreach (PermissionCatalog::MODULES as $module => $label) {
            $fields[] = Section::make($label)->collapsible()->collapsed()->schema([
                CheckboxList::make('modules.'.$module)->label('Berechtigungen')->options(PermissionCatalog::functions($module))
                    ->columns(2)->searchable()->default([])->live()
                    ->afterStateUpdated(function (?array $state, Set $set) use ($module) {
                        $state ??= [];
                        if ($state !== [] && ! in_array('view', $state, true)) {
                            $set('modules.'.$module, ['view', ...$state]);
                        }
                    }),
            ]);
        }
        return [...$fields, Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500),
            Hidden::make('revision')->default(0)];
    }

    public static function formValues(AccessProfile $record, bool $copy = false): array
    {
        $modules = [];
        foreach ($record->permissions as $permission) {
            [$module, $function] = explode('.', $permission, 2);
            $modules[$module][] = $function;
        }

        return ['name' => $copy ? $record->name.' (Kopie)' : $record->name,
            'description' => $record->description, 'active' => $record->active,
            'modules' => $modules, 'revision' => $copy ? 0 : $record->revision];
    }

    public static function input(array $data): array
    {
        $permissions = [];
        foreach ($data['modules'] ?? [] as $module => $functions) {
            foreach ($functions as $function) {
                $permissions[] = $module.'.'.$function;
            }
        }
        unset($data['modules']);

        return $data + ['permissions' => $permissions];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Profil')->searchable()->sortable(),
            TextColumn::make('description')->label('Beschreibung')->limit(80),
            TextColumn::make('permission_count')->label('Rechte')->getStateUsing(fn (AccessProfile $record) => count($record->permissions)),
            IconColumn::make('active')->label('Aktiv')->boolean(),
            TextColumn::make('revision')->label('Version'),
        ])->filters([TernaryFilter::make('active')->label('Aktiv')])
            ->headerActions([Action::make('newProfile')->label('Profil erstellen')->icon('heroicon-o-plus')
                ->schema(self::fields())->action(fn (array $data, $livewire) => FormMutation::run($livewire,
                    fn () => app(AccessProfileService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, self::input($data))))])
            ->recordActions([
                Action::make('editProfile')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Profil bearbeiten')
                    ->schema(self::fields())->fillForm(fn (AccessProfile $record) => self::formValues($record))
                    ->action(fn (AccessProfile $record, array $data, $livewire) => FormMutation::run($livewire,
                        fn () => app(AccessProfileService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, self::input($data)))),
                Action::make('copyProfile')->label('Kopieren')->icon('heroicon-o-document-duplicate')->iconButton()->tooltip('Profil kopieren')
                    ->schema(self::fields())->fillForm(fn (AccessProfile $record) => self::formValues($record, true))
                    ->action(fn (array $data, $livewire) => FormMutation::run($livewire,
                        fn () => app(AccessProfileService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, self::input($data)))),
            ])->defaultSort('name')->deferFilters(false);
    }

    public static function getPages(): array
    {
        return ['index' => AccessProfileResource\Pages\ManageProfiles::route('/')];
    }
}
