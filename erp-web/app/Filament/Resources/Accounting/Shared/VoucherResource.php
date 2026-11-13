<?php

namespace App\Filament\Resources\Accounting\Shared;

use App\Enums\Module;
use App\Enums\VoucherType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Models\Voucher;
use App\Services\VoucherService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Base for the cash box voucher resources (one per VoucherType, all on the `vouchers` table).
 * Subclasses set $documentType, $permissionKey, labels, slug, icon, sort and getPages().
 */
abstract class VoucherResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = Voucher::class;

    protected static ?Module $module = Module::Accounting;

    protected static string $documentService = VoucherService::class;

    protected static string $printType = 'voucher';

    public static function voucherType(): VoucherType
    {
        return VoucherType::from((string) static::$documentType);
    }

    public static function form(Schema $schema): Schema
    {
        return VoucherForm::configure($schema, static::voucherType());
    }

    public static function table(Table $table): Table
    {
        return VoucherTable::configure($table, static::class, static::voucherType());
    }
}
