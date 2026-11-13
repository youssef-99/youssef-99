<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\ChequeReceiptVoucherResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Cheque receipt vouchers (سندات قبض شيكات) — /accounting/receipt-cheque-vouchers.
 */
class ChequeReceiptVoucherResource extends VoucherResource
{
    protected static ?string $documentType = 'receipt_cheque';

    protected static ?string $permissionKey = 'accounting.receipt-cheque-vouchers';

    protected static ?string $modelLabel = 'Cheque Receipt Voucher';

    protected static ?string $pluralModelLabel = 'Cheque Receipt Vouchers';

    protected static ?string $slug = 'accounting/receipt-cheque-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 100;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChequeReceiptVouchers::route('/'),
            'create' => Pages\CreateChequeReceiptVoucher::route('/create'),
            'view' => Pages\ViewChequeReceiptVoucher::route('/{record}'),
            'edit' => Pages\EditChequeReceiptVoucher::route('/{record}/edit'),
        ];
    }
}
