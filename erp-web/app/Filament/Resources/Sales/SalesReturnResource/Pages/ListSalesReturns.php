<?php

namespace App\Filament\Resources\Sales\SalesReturnResource\Pages;

use App\Filament\Resources\Sales\SalesReturnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalesReturns extends ListRecords
{
    protected static string $resource = SalesReturnResource::class;

    /** All / Posted / Unposted (الكل / المرحلة / الغير مرحلة) */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'posted' => Tab::make(__('Posted'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', true)),
            'unposted' => Tab::make(__('Unposted'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', false)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
