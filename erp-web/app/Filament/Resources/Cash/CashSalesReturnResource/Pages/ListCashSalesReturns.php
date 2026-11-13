<?php

namespace App\Filament\Resources\Cash\CashSalesReturnResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Cash\CashSalesReturnResource;
use App\Filament\Resources\Purchases\Shared\CreatesDocumentInModal;
use Filament\Resources\Pages\ListRecords;

class ListCashSalesReturns extends ListRecords
{
    use CreatesDocumentInModal;
    use HasPostedTabs;

    protected static string $resource = CashSalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->documentCreateAction()];
    }
}
