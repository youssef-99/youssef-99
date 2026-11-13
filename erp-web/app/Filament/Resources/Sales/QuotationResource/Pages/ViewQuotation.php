<?php

namespace App\Filament\Resources\Sales\QuotationResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Filament\Resources\Sales\QuotationResource;
use App\Filament\Resources\Sales\Shared\ConvertToInvoiceAction;
use App\Services\SalesDocumentService;
use Filament\Actions\EditAction;

class ViewQuotation extends ViewDocumentRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DocumentActions::post(SalesDocumentService::class, QuotationResource::class, 'Confirm'),
            DocumentActions::unpost(SalesDocumentService::class, QuotationResource::class),
            ConvertToInvoiceAction::make(),
            DocumentActions::print('sales'),
        ];
    }
}
