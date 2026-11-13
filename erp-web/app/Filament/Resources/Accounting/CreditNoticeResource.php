<?php

namespace App\Filament\Resources\Accounting;

use App\Filament\Resources\Accounting\CreditNoticeResource\Pages;
use App\Filament\Resources\Accounting\Shared\VoucherResource;

/**
 * Credit notices (إشعارات دائنة) — /accounting/notice-creditors.
 */
class CreditNoticeResource extends VoucherResource
{
    protected static ?string $documentType = 'credit_notice';

    protected static ?string $permissionKey = 'accounting.notice-creditors';

    protected static ?string $modelLabel = 'Credit Notice';

    protected static ?string $pluralModelLabel = 'Credit Notices';

    protected static ?string $slug = 'accounting/notice-creditors';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?int $navigationSort = 80;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditNotices::route('/'),
            'create' => Pages\CreateCreditNotice::route('/create'),
            'view' => Pages\ViewCreditNotice::route('/{record}'),
            'edit' => Pages\EditCreditNotice::route('/{record}/edit'),
        ];
    }
}
