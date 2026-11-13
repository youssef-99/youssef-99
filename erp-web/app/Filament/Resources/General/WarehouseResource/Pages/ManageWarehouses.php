<?php

namespace App\Filament\Resources\General\WarehouseResource\Pages;

use App\Filament\Resources\General\WarehouseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWarehouses extends ManageRecords
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
