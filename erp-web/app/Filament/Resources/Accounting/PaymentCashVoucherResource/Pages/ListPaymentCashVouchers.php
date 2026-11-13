<?php

namespace App\Filament\Resources\Accounting\PaymentCashVoucherResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\PaymentCashVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentCashVouchers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PaymentCashVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
