<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages;
use App\Filament\Resources\Pos\Shared\PosDocumentForm;
use App\Filament\Resources\Pos\Shared\PosDocumentTable;
use App\Models\SalesDocument;
use App\Services\PosService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * POS sales invoices (فواتير مبيعات نقاط البيع) — /sales/pos-sales-invoices.
 */
class PosSalesInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.pos-sales-invoices';

    protected static ?string $documentType = 'pos_invoice';

    protected static string $documentService = PosService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'POS Sales Invoice';

    protected static ?string $pluralModelLabel = 'POS Sales Invoices';

    protected static ?string $slug = 'sales/pos-sales-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return PosDocumentForm::invoice($schema);
    }

    public static function table(Table $table): Table
    {
        return PosDocumentTable::configure($table, static::class);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosSalesInvoices::route('/'),
            'create' => Pages\CreatePosSalesInvoice::route('/add'),
            'view' => Pages\ViewPosSalesInvoice::route('/{record}/view'),
            'edit' => Pages\EditPosSalesInvoice::route('/{record}/edit'),
        ];
    }
}
