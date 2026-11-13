<?php

namespace App\Filament\Resources\Sales\CustomerOrderResource\Pages;

use App\Filament\Resources\Pages\EditDocumentRecord;
use App\Filament\Resources\Sales\CustomerOrderResource;

class EditCustomerOrder extends EditDocumentRecord
{
    protected static string $resource = CustomerOrderResource::class;
}
