<?php

namespace App\Filament\Resources\Accounting\PaymentChequeVoucherResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\PaymentChequeVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentChequeVouchers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PaymentChequeVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
