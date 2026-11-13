<?php

namespace App\Filament\Resources\Sales\CustomerOrderResource\Pages;

use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Sales\CustomerOrderResource;

class ViewCustomerOrder extends ViewDocumentRecord
{
    protected static string $resource = CustomerOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), ...CustomerOrderResource::workflowActions()];
    }
}
