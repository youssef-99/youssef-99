<?php

namespace App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pos\PosSalesInvoiceResource;
use App\Filament\Resources\Pos\Shared\PosDocumentTable;
use App\Services\PosService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPosSalesInvoice extends ViewRecord
{
    protected static string $resource = PosSalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DocumentActions::post(PosService::class, static::getResource()),
            DocumentActions::unpost(PosService::class, static::getResource()),
            DocumentActions::print('sales', 'printA4', 'Print A4'),
            PosDocumentTable::print80mm(),
        ];
    }
}
