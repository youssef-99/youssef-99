<?php

namespace App\Filament\Resources\Sales\CustomerGroupResource\Pages;

use App\Filament\Resources\Sales\CustomerGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomerGroups extends ManageRecords
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
