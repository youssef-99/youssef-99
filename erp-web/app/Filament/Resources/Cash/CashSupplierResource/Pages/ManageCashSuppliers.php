<?php

namespace App\Filament\Resources\Cash\CashSupplierResource\Pages;

use App\Filament\Resources\Cash\CashSupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCashSuppliers extends ManageRecords
{
    protected static string $resource = CashSupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
