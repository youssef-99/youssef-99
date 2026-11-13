<?php

namespace App\Filament\Resources\General\StakeholderResource\Pages;

use App\Filament\Resources\General\StakeholderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStakeholders extends ManageRecords
{
    protected static string $resource = StakeholderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
