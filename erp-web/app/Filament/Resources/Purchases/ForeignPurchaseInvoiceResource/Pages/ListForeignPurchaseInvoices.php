<?php

namespace App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForeignPurchaseInvoices extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = ForeignPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
