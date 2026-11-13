<?php

namespace App\Filament\Resources\Accounting\ChequeReceiptVoucherResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\ChequeReceiptVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChequeReceiptVouchers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = ChequeReceiptVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
