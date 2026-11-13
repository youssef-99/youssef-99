<?php

namespace App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource\Pages;

use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewForeignPurchaseInvoice extends ViewDocumentRecord
{
    protected static string $resource = ForeignPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::printGoodsReceiptNote(), PurchaseActions::sendToRasd(static::$resource), PurchaseActions::prepareRasdFile(), PurchaseActions::back(static::$resource),
        ];
    }
}
