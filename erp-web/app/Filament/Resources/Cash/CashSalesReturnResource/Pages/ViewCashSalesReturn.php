<?php

namespace App\Filament\Resources\Cash\CashSalesReturnResource\Pages;

use App\Filament\Resources\Cash\CashSalesReturnResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewCashSalesReturn extends ViewDocumentRecord
{
    protected static string $resource = CashSalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::back(static::$resource),
        ];
    }
}
