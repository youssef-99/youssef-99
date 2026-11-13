<?php

namespace App\Filament\Resources\Inventory\PeriodicStocktakeResource\Pages;

use App\Filament\Resources\Inventory\PeriodicStocktakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPeriodicStocktakes extends ListRecords
{
    protected static string $resource = PeriodicStocktakeResource::class;

    /** All / Approved / Unapproved (الكل / المعتمدة / الغير معتمدة) */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'approved' => Tab::make(__('Approved'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)),
            'unapproved' => Tab::make(__('Unapproved'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
