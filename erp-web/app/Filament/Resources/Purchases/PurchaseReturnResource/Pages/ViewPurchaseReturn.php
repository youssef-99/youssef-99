<?php

namespace App\Filament\Resources\Purchases\PurchaseReturnResource\Pages;

use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Purchases\PurchaseReturnResource;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;

class ViewPurchaseReturn extends ViewDocumentRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            PurchaseActions::back(static::$resource),
        ];
    }
}
