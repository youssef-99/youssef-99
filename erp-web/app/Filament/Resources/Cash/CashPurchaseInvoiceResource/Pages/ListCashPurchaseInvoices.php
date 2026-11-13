<?php

namespace App\Filament\Resources\Cash\CashPurchaseInvoiceResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Cash\CashPurchaseInvoiceResource;
use App\Filament\Resources\Purchases\Shared\CreatesDocumentInModal;
use Filament\Resources\Pages\ListRecords;

class ListCashPurchaseInvoices extends ListRecords
{
    use CreatesDocumentInModal;
    use HasPostedTabs;

    protected static string $resource = CashPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->documentCreateAction()];
    }
}
