<?php

namespace App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseInvoices extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
