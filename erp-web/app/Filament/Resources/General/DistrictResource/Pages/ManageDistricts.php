<?php

namespace App\Filament\Resources\General\DistrictResource\Pages;

use App\Filament\Resources\General\DistrictResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDistricts extends ManageRecords
{
    protected static string $resource = DistrictResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
