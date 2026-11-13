<?php

namespace App\Filament\Resources\Accounting\JournalVoucherResource\Pages;

use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Accounting\JournalVoucherResource;
use App\Filament\Resources\Pages\ViewDocumentRecord;
use App\Services\ManualJournalService;
use Filament\Actions\EditAction;

class ViewJournalVoucher extends ViewDocumentRecord
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DocumentActions::post(ManualJournalService::class, JournalVoucherResource::class),
            JournalVoucherResource::unpostAction(),
            DocumentActions::print('journal', label: 'Print Voucher'),
        ];
    }
}
