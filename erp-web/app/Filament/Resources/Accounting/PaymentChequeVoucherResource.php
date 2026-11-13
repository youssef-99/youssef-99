<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\PaymentChequeVoucherResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Cheque payment vouchers (سندات الصرف بشيكات) — /accounting/payment-cheque-vouchers.
 */
class PaymentChequeVoucherResource extends VoucherResource
{
    protected static ?string $documentType = 'payment_cheque';

    protected static ?string $permissionKey = 'accounting.payment-cheque-vouchers';

    protected static ?string $modelLabel = 'Cheque Payment Voucher';

    protected static ?string $pluralModelLabel = 'Cheque Payment Vouchers';

    protected static ?string $slug = 'accounting/payment-cheque-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 70;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentChequeVouchers::route('/'),
            'create' => Pages\CreatePaymentChequeVoucher::route('/create'),
            'view' => Pages\ViewPaymentChequeVoucher::route('/{record}'),
            'edit' => Pages\EditPaymentChequeVoucher::route('/{record}/edit'),
        ];
    }
}
