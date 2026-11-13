<?php

namespace App\Filament\Resources\Purchases\PurchaseOrderResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\PurchaseOrderResource;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use Filament\Actions\EditAction;

class ViewPurchaseOrder extends ViewDocumentRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            PurchaseActions::convertToInvoice(),
            DocumentActions::print(PurchaseOrderResource::getPrintType()),
            PurchaseActions::back(static::$resource),
        ];
    }
}
