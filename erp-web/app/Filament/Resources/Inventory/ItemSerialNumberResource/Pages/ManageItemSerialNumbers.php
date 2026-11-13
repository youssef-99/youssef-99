<?php

namespace App\Filament\Resources\Inventory\ItemSerialNumberResource\Pages;

use App\Filament\Resources\Inventory\ItemSerialNumberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItemSerialNumbers extends ManageRecords
{
    protected static string $resource = ItemSerialNumberResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
