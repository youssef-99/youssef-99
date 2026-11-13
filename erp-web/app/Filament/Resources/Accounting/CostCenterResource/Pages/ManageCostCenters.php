<?php

namespace App\Filament\Resources\Accounting\CostCenterResource\Pages;

use App\Filament\Resources\Accounting\CostCenterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCenters extends ManageRecords
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
