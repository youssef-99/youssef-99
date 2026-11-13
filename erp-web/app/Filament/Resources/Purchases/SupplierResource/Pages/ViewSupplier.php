<?php

namespace App\Filament\Resources\Purchases\SupplierResource\Pages;

use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use App\Filament\Resources\Purchases\SupplierResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), PurchaseActions::back(SupplierResource::class)];
    }
}
