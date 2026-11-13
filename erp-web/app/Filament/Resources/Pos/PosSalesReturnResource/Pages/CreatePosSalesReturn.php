<?php

namespace App\Filament\Resources\Pos\PosSalesReturnResource\Pages;

use App\Filament\Resources\Pages\CreateDocumentRecord;
use App\Filament\Resources\Pos\PosSalesReturnResource;

class CreatePosSalesReturn extends CreateDocumentRecord
{
    protected static string $resource = PosSalesReturnResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        $data['time'] = now()->format('H:i:s');

        return $data;
    }
}
