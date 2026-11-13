<?php

namespace App\Filament\Resources\General\EgsUnitResource\Pages;

use App\Filament\Resources\General\EgsUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEgsUnits extends ManageRecords
{
    protected static string $resource = EgsUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
