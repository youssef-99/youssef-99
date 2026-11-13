<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Actions\DocumentActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

abstract class ViewDocumentRecord extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            EditAction::make(),
            DocumentActions::post($resource::getDocumentService(), $resource),
            DocumentActions::unpost($resource::getDocumentService(), $resource),
            DocumentActions::print($resource::getPrintType()),
        ];
    }
}
