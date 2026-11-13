<?php

namespace App\Filament\Resources\General\ActivityResource\Pages;

use App\Filament\Resources\General\ActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageActivities extends ManageRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
