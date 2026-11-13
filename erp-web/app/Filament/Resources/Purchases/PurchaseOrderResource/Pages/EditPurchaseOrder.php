<?php

namespace App\Filament\Resources\Purchases\PurchaseOrderResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pages\EditDocumentRecord;
use App\Filament\Resources\Purchases\PurchaseOrderResource;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;

class EditPurchaseOrder extends EditDocumentRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PurchaseActions::convertToInvoice(),
            DocumentActions::print(PurchaseOrderResource::getPrintType()),
            DeleteAction::make(),
        ];
    }
}
