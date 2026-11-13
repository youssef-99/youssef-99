<?php

namespace App\Filament\Resources\Accounting\JournalVoucherResource\Pages;

use App\Filament\Resources\Accounting\JournalVoucherResource;
use App\Filament\Resources\Pages\CreateDocumentRecord;

class CreateJournalVoucher extends CreateDocumentRecord
{
    protected static string $resource = JournalVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['entry_type'] = 'manual';

        return parent::mutateFormDataBeforeCreate($data);
    }
}
