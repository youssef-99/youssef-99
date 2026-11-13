<?php

namespace App\Filament\Resources\Purchases\PurchaseOrderResource\Pages;

use App\Filament\Resources\Purchases\PurchaseOrderResource;
use App\Filament\Resources\Purchases\Shared\CreatesDocumentInModal;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    use CreatesDocumentInModal;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->documentCreateAction()];
    }
}
