<?php

namespace App\Filament\Resources\Cash\CashSalesInvoiceResource\Pages;

use App\Filament\Resources\Cash\CashSalesInvoiceResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewCashSalesInvoice extends ViewDocumentRecord
{
    protected static string $resource = CashSalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::back(static::$resource),
        ];
    }
}
