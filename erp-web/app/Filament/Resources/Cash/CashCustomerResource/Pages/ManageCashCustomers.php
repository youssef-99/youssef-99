<?php

namespace App\Filament\Resources\Cash\CashCustomerResource\Pages;

use App\Filament\Resources\Cash\CashCustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCashCustomers extends ManageRecords
{
    protected static string $resource = CashCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
