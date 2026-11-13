<?php

namespace App\Filament\Resources\General\RegionResource\Pages;

use App\Filament\Resources\General\RegionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRegions extends ManageRecords
{
    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
