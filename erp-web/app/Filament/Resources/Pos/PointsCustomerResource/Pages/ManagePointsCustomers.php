<?php

namespace App\Filament\Resources\Pos\PointsCustomerResource\Pages;

use App\Filament\Resources\Pos\PointsCustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePointsCustomers extends ManageRecords
{
    protected static string $resource = PointsCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
