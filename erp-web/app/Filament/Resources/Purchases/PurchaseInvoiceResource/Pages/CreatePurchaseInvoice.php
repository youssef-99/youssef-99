<?php

namespace App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages;

use App\Filament\Resources\Pages\CreateDocumentRecord;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource;

class CreatePurchaseInvoice extends CreateDocumentRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;
}
