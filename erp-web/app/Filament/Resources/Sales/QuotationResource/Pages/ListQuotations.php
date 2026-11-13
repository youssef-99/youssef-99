<?php

namespace App\Filament\Resources\Sales\QuotationResource\Pages;

use App\Filament\Resources\Sales\QuotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    /** All / Confirmed / Unconfirmed (الكل / المؤكدة / الغير مؤكدة) */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'confirmed' => Tab::make(__('Confirmed'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', true)),
            'unconfirmed' => Tab::make(__('Unconfirmed'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', false)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
