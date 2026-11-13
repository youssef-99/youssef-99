<?php

namespace App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages;

use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewPurchaseInvoice extends ViewDocumentRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::printGoodsReceiptNote(), PurchaseActions::sendToRasd(static::$resource), PurchaseActions::prepareRasdFile(), PurchaseActions::back(static::$resource),
        ];
    }
}
