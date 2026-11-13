<?php

namespace App\Filament\Resources\General\CountryResource\Pages;

use App\Filament\Resources\General\CountryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCountries extends ManageRecords
{
    protected static string $resource = CountryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
