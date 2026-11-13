<?php

namespace App\Filament\Resources\Inventory\ManufacturerResource\Pages;

use App\Filament\Resources\Inventory\ManufacturerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageManufacturers extends ManageRecords
{
    protected static string $resource = ManufacturerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
