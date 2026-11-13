<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\PaymentCashVoucherResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Cash payment vouchers (سندات الصرف النقدية) — /accounting/payment-cash-vouchers.
 */
class PaymentCashVoucherResource extends VoucherResource
{
    protected static ?string $documentType = 'payment_cash';

    protected static ?string $permissionKey = 'accounting.payment-cash-vouchers';

    protected static ?string $modelLabel = 'Cash Payment Voucher';

    protected static ?string $pluralModelLabel = 'Cash Payment Vouchers';

    protected static ?string $slug = 'accounting/payment-cash-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?int $navigationSort = 60;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentCashVouchers::route('/'),
            'create' => Pages\CreatePaymentCashVoucher::route('/add'),
            'view' => Pages\ViewPaymentCashVoucher::route('/{record}/view'),
            'edit' => Pages\EditPaymentCashVoucher::route('/{record}/edit'),
        ];
    }
}
