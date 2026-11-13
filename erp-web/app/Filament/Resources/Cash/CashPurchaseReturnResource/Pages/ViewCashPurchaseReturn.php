<?php

namespace App\Filament\Resources\Cash\CashPurchaseReturnResource\Pages;

use App\Filament\Resources\Cash\CashPurchaseReturnResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewCashPurchaseReturn extends ViewDocumentRecord
{
    protected static string $resource = CashPurchaseReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::back(static::$resource),
        ];
    }
}
