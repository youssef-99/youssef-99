<?php

namespace App\Filament\Resources\Sales\QuotationResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pages\EditDocumentRecord;
use App\Filament\Resources\Sales\QuotationResource;
use App\Services\SalesDocumentService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;

class EditQuotation extends EditDocumentRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DocumentActions::post(SalesDocumentService::class, QuotationResource::class, 'Confirm'),
            DocumentActions::print('sales'),
            DeleteAction::make(),
        ];
    }
}
