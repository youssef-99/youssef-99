<?php

namespace App\Filament\Resources\Pos\Shared;

use App\Enums\Module;
use App\Enums\Permission;
use App\Filament\Resources\ErpResource;
use App\Models\SalesDocument;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the ZATCA list pages over posted sales documents (no create / edit / delete).
 * Subclasses set `$zatcaTypes`.
 */
abstract class ZatcaResource extends ErpResource
{
    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    /** @var array<int, string> */
    protected static array $zatcaTypes = [];

    /** @return array<int, string> */
    public static function getZatcaTypes(): array
    {
        return static::$zatcaTypes;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', static::$zatcaTypes)->where('is_posted', true);
    }

    /** @return array<Permission> */
    public static function getPermissionActions(): array
    {
        return [Permission::View, Permission::Post];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ZatcaDocumentTable::configure($table, static::class, static::$zatcaTypes);
    }
}
