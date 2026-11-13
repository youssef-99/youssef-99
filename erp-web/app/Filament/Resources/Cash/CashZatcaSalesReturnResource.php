<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashZatcaSalesReturnResource\Pages\ListCashZatcaSalesReturns;
use App\Filament\Resources\Pos\Shared\ZatcaDocumentTable;
use App\Filament\Resources\Pos\Shared\ZatcaResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ZATCA Sales Returns (مرتجعات المبيعات الضريبية النقدية) — /cash/zatca-sales-return-invoices.
 * Posted cash documents with their ZATCA submission status (shared POS ZATCA table).
 */
class CashZatcaSalesReturnResource extends ZatcaResource
{
    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.zatca-sales-return-invoices';

    /** @var array<int, string> */
    protected static array $zatcaTypes = ['cash_sales_return'];

    protected static ?string $modelLabel = 'ZATCA Sales Return';

    protected static ?string $pluralModelLabel = 'ZATCA Sales Returns';

    protected static ?string $slug = 'cash/zatca-sales-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 100;

    public static function table(Table $table): Table
    {
        return ZatcaDocumentTable::configure($table, static::class, static::$zatcaTypes, [TextColumn::make('originalDocument.number')->label('Original Sales Invoice')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashZatcaSalesReturns::route('/'),
        ];
    }
}
