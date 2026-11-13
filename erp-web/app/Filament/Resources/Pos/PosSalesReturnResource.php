<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\PosSalesReturnResource\Pages;
use App\Filament\Resources\Pos\Shared\PosDocumentForm;
use App\Filament\Resources\Pos\Shared\PosDocumentTable;
use App\Models\SalesDocument;
use App\Services\PosService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * POS sales returns (مرتجع مبيعات نقاط البيع) — /sales/pos-sales-return-invoices.
 */
class PosSalesReturnResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.pos-sales-return-invoices';

    protected static ?string $documentType = 'pos_return';

    protected static string $documentService = PosService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'POS Sales Return';

    protected static ?string $pluralModelLabel = 'POS Sales Returns';

    protected static ?string $slug = 'sales/pos-sales-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 81;

    public static function form(Schema $schema): Schema
    {
        return PosDocumentForm::return($schema);
    }

    public static function table(Table $table): Table
    {
        return PosDocumentTable::configure($table, static::class, isReturn: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosSalesReturns::route('/'),
            'create' => Pages\CreatePosSalesReturn::route('/add'),
            'view' => Pages\ViewPosSalesReturn::route('/{record}/view'),
            'edit' => Pages\EditPosSalesReturn::route('/{record}/edit'),
        ];
    }
}
