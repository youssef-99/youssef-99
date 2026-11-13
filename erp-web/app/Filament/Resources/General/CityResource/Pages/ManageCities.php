<?php

namespace App\Filament\Resources\General\CityResource\Pages;

use App\Filament\Resources\General\CityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCities extends ManageRecords
{
    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
