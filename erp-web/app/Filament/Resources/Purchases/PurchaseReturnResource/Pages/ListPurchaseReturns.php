<?php

namespace App\Filament\Resources\Purchases\PurchaseReturnResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Purchases\PurchaseReturnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseReturns extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PurchaseReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
