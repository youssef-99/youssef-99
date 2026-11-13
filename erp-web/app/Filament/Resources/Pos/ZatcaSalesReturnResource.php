<?php

namespace App\Filament\Resources\Pos;

use App\Filament\Resources\Pos\Shared\ZatcaResource;
use App\Filament\Resources\Pos\ZatcaSalesReturnResource\Pages\ListZatcaSalesReturns;

/**
 * ZATCA sales returns (مرتجعات المبيعات - هيئة الزكاة) — /sales/zatca-sales-return-invoices.
 */
class ZatcaSalesReturnResource extends ZatcaResource
{
    protected static ?string $permissionKey = 'sales.zatca-sales-return-invoices';

    protected static array $zatcaTypes = ['sales_return', 'pos_return'];

    protected static ?string $modelLabel = 'ZATCA Sales Return';

    protected static ?string $pluralModelLabel = 'ZATCA Sales Returns';

    protected static ?string $slug = 'sales/zatca-sales-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?int $navigationSort = 91;

    public static function getPages(): array
    {
        return [
            'index' => ListZatcaSalesReturns::route('/'),
        ];
    }
}
