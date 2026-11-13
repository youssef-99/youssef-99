<?php

namespace App\Filament\Resources\Sales\CustomerOrderResource\Pages;

use App\Filament\Resources\Sales\CustomerOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCustomerOrders extends ListRecords
{
    protected static string $resource = CustomerOrderResource::class;

    /** All / Posted / Rejected / Partially Approved / Approved (الكل / المرحلة / المرفوضة / المعتمدة جزئيا / المعتمدة) */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'posted' => Tab::make(__('Posted'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'confirmed')),
            'rejected' => Tab::make(__('Rejected'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'partially_approved' => Tab::make(__('Partially Approved'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'partially_approved')),
            'approved' => Tab::make(__('Approved'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
