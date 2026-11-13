<?php

namespace App\Filament\Resources\Pos\PosSalesReturnResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Pos\PosSalesReturnResource;
use App\Filament\Resources\Pos\Shared\PosDocumentTable;
use App\Services\PosService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPosSalesReturn extends ViewRecord
{
    protected static string $resource = PosSalesReturnResource::class;

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
