<?php

namespace App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource\Pages;

use App\Filament\Resources\Pages\CreateDocumentRecord;
use App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource;

class CreateForeignPurchaseInvoice extends CreateDocumentRecord
{
    protected static string $resource = ForeignPurchaseInvoiceResource::class;
}
