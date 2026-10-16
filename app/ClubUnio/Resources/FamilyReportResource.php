<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FamilyReportResource extends FamilyMemberResource
{
    protected static ?string $pluralModelLabel = 'Familien mit Kindern';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);

        return parent::getEloquentQuery()->whereHas('family', fn ($q) => $q->where('active', true))
            ->whereHas('member', fn ($q) => $q->whereNull('archived_at'))
            ->with(['tenant', 'family', 'member.status', 'member.type']);
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([...array_values($table->getColumns()),
            TextColumn::make('family.street')->label('Strasse')->toggleable(),
            TextColumn::make('family.postal_code')->label('PLZ')->toggleable(),
            TextColumn::make('family.city')->label('Ort')->searchable()->toggleable(),
            TextColumn::make('member.birth_date')->label('Geburtsdatum')->date('d.m.Y')->sortable()->toggleable(),
            TextColumn::make('member.type.name')->label('Mitgliederart')->badge(),
            TextColumn::make('member.status.name')->label('Mitgliederstatus')->badge(),
        ])->filters([...array_values($table->getFilters()),
            SelectFilter::make('member_status')->label('Mitgliederstatus')
                ->options(fn () => MemberResource::lookupOptions(MemberStatus::class, null))
                ->query(fn (Builder $query, array $data) => static::filterMember($query, 'status_id', $data['value'] ?? null)),
            SelectFilter::make('member_type')->label('Mitgliederart')
                ->options(fn () => MemberResource::lookupOptions(MemberType::class, null))
                ->query(fn (Builder $query, array $data) => static::filterMember($query, 'type_id', $data['value'] ?? null)),
        ])->headerActions([ReportExportService::action('families')])->recordActions([
            ViewAction::make('member')->iconButton()->tooltip('Mitgliederdossier')
                ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
            ViewAction::make('family')->icon('heroicon-o-user-group')->iconButton()->tooltip('Familiendossier')
                ->url(fn ($record) => FamilyResource::getUrl('view', ['record' => $record->family_id])),
        ])->paginated([25, 50, 100]);
    }

    private static function filterMember(Builder $query, string $field, mixed $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return is_scalar($value) && ctype_digit((string) $value)
            ? $query->whereHas('member', fn ($q) => $q->where($field, $value))
            : $query->whereRaw('1=0');
    }

    public static function getPages(): array
    {
        return ['index' => FamilyReportResource\Pages\ListFamilies::route('/')];
    }
}
