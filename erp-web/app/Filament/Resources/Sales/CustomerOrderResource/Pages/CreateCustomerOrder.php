<?php

namespace App\Filament\Resources\Sales\CustomerOrderResource\Pages;

use App\Filament\Resources\Pages\CreateDocumentRecord;
use App\Filament\Resources\Sales\CustomerOrderResource;

class CreateCustomerOrder extends CreateDocumentRecord
{
    protected static string $resource = CustomerOrderResource::class;
}
