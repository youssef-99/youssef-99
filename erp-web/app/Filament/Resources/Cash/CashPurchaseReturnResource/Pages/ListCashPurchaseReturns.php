<?php

namespace App\Filament\Resources\Cash\CashPurchaseReturnResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Cash\CashPurchaseReturnResource;
use App\Filament\Resources\Purchases\Shared\CreatesDocumentInModal;
use Filament\Resources\Pages\ListRecords;

class ListCashPurchaseReturns extends ListRecords
{
    use CreatesDocumentInModal;
    use HasPostedTabs;

    protected static string $resource = CashPurchaseReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->documentCreateAction()];
    }
}
