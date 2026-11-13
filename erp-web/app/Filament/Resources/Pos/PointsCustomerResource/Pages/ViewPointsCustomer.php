<?php

namespace App\Filament\Resources\Pos\PointsCustomerResource\Pages;

use App\Filament\Resources\Pos\PointsCustomerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPointsCustomer extends ViewRecord
{
    protected static string $resource = PointsCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
