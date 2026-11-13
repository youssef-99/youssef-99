<?php

namespace App\Filament\Resources\Accounting\ChequeCollectionResource\Pages;

use App\Filament\Resources\Accounting\ChequeCollectionResource;
use App\Filament\Resources\Pages\EditDocumentRecord;

class EditChequeCollection extends EditDocumentRecord
{
    protected static string $resource = ChequeCollectionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return parent::mutateFormDataBeforeSave(ChequeCollectionResource::dehydrateSelection($data));
    }
}
