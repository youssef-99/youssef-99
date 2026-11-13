<?php

namespace App\Filament\Resources\Inventory\PositiveAdjustmentResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Inventory\PositiveAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPositiveAdjustments extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PositiveAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
