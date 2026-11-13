<?php

namespace App\Filament\Concerns;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For resources over a shared document table (sales_documents, purchase_documents,
 * stock_documents, vouchers). Subclasses set:
 *
 *   protected static ?string $documentType = 'sales_invoice';            // `type` column value (null = no scope)
 *   protected static string $documentService = SalesDocumentService::class; // post/unpost/recalculate
 *   protected static string $printType = 'sales';                          // PrintController type
 *
 * Posted documents are read-only (no edit/delete).
 */
trait IsDocumentResource
{
    public static function getDocumentType(): ?string
    {
        return static::$documentType;
    }

    public static function getDocumentService(): string
    {
        return static::$documentService;
    }

    public static function getPrintType(): string
    {
        return static::$printType ?? 'sales';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(static::$documentType, fn (Builder $q, $type) => $q->where('type', $type));
    }

    /** @return array<Permission> */
    public static function getPermissionActions(): array
    {
        return [Permission::View, Permission::Create, Permission::Edit, Permission::Delete, Permission::Post];
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->is_posted && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->is_posted && parent::canDelete($record);
    }
}
