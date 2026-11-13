<?php

namespace App\Filament\Resources\Inventory\NegativeAdjustmentResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Inventory\NegativeAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNegativeAdjustments extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = NegativeAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
