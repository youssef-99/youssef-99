<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\ReceiptVoucherResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Receipt vouchers (سندات القبض) — /accounting/receipt-vouchers.
 */
class ReceiptVoucherResource extends VoucherResource
{
    protected static ?string $documentType = 'receipt_cash';

    protected static ?string $permissionKey = 'accounting.receipt-vouchers';

    protected static ?string $modelLabel = 'Receipt Voucher';

    protected static ?string $pluralModelLabel = 'Receipt Vouchers';

    protected static ?string $slug = 'accounting/receipt-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-circle';

    protected static ?int $navigationSort = 50;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReceiptVouchers::route('/'),
            'create' => Pages\CreateReceiptVoucher::route('/add'),
            'view' => Pages\ViewReceiptVoucher::route('/{record}/view'),
            'edit' => Pages\EditReceiptVoucher::route('/{record}/edit'),
        ];
    }
}
