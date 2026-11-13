<?php

namespace App\Filament\Resources\Cash\CashSalesInvoiceResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Cash\CashSalesInvoiceResource;
use App\Filament\Resources\Purchases\Shared\CreatesDocumentInModal;
use Filament\Resources\Pages\ListRecords;

class ListCashSalesInvoices extends ListRecords
{
    use CreatesDocumentInModal;
    use HasPostedTabs;

    protected static string $resource = CashSalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->documentCreateAction()];
    }
}
