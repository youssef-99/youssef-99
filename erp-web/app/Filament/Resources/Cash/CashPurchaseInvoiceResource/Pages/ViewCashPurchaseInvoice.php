<?php

namespace App\Filament\Resources\Cash\CashPurchaseInvoiceResource\Pages;

use App\Filament\Resources\Cash\CashPurchaseInvoiceResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewCashPurchaseInvoice extends ViewDocumentRecord
{
    protected static string $resource = CashPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::back(static::$resource),
        ];
    }
}
