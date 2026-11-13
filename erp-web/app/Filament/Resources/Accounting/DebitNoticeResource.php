<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\DebitNoticeResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Debit notices (إشعارات مدينة) — /accounting/notice-debtors.
 */
class DebitNoticeResource extends VoucherResource
{
    protected static ?string $documentType = 'debit_notice';

    protected static ?string $permissionKey = 'accounting.notice-debtors';

    protected static ?string $modelLabel = 'Debit Notice';

    protected static ?string $pluralModelLabel = 'Debit Notices';

    protected static ?string $slug = 'accounting/notice-debtors';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 90;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDebitNotices::route('/'),
            'create' => Pages\CreateDebitNotice::route('/create'),
            'view' => Pages\ViewDebitNotice::route('/{record}'),
            'edit' => Pages\EditDebitNotice::route('/{record}/edit'),
        ];
    }
}
