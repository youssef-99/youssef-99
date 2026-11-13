<?php

namespace App\Filament\Resources\Purchases\AccreditationResource\Pages;

use App\Filament\Resources\Purchases\AccreditationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAccreditations extends ManageRecords
{
    protected static string $resource = AccreditationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
