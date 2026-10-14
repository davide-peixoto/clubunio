<?php

namespace App\ClubUnio\Resources\MemberReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\MemberReportResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemberReports extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = MemberReportResource::class;

    public function getTabs(): array
    {
        $fields = ['honorary' => 'Ehrenmitglieder', 'free_membership' => 'Freimitglieder', 'supporter' => 'Supporter', 'sponsor' => 'Sponsoren', 'spots' => 'Spots'];
        $select = ['count(*) as total'];
        foreach (array_keys($fields) as $field) {
            $select[] = "count(*) FILTER (WHERE {$field}) as {$field}";
        }
        $counts = MemberReportResource::getEloquentQuery()->selectRaw(implode(', ', $select))->toBase()->first();
        $tabs = ['all' => Tab::make('Alle ('.$counts->total.')')];
        foreach ($fields as $field => $label) {
            $tabs[$field] = Tab::make($label.' ('.$counts->$field.')')
                ->modifyQueryUsing(fn (Builder $query) => $query->where($field, true));
        }

        return $tabs;
    }
}
