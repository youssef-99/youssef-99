<?php

namespace App\Filament\Resources\Accounting\ChequeCollectionResource\Pages;

use App\Filament\Resources\Accounting\ChequeCollectionResource;
use App\Filament\Resources\Pages\CreateDocumentRecord;

class CreateChequeCollection extends CreateDocumentRecord
{
    protected static string $resource = ChequeCollectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return parent::mutateFormDataBeforeCreate(ChequeCollectionResource::dehydrateSelection($data));
    }
}
