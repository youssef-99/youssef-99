<?php

namespace App\Filament\Resources\Inventory\InboundTransferResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Inventory\InboundTransferResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Services\StockDocumentService;
use Filament\Actions\EditAction;

class ViewInboundTransfer extends ViewDocumentRecord
{
    protected static string $resource = InboundTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DocumentActions::post(StockDocumentService::class, InboundTransferResource::class, 'Receive'),
            DocumentActions::unpost(StockDocumentService::class, InboundTransferResource::class),
            DocumentActions::print('stock'),
        ];
    }
}
