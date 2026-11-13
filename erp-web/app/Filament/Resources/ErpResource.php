<?php

namespace App\Filament\Resources;

use App\Enums\Module;
use App\Enums\Permission;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Base class for every ERP resource.
 *
 * Subclasses set:
 *   protected static ?Module $module = Module::Sales;      // sidebar group + permission tree node
 *   protected static ?string $permissionKey = 'sales.customers'; // optional, defaults to module.slug
 *   protected static ?string $modelLabel = 'Customer';      // English; translated through lang/modules/* JSON
 *   protected static ?string $pluralModelLabel = 'Customers';
 *
 * Authorization: group permissions "{key}.view|create|edit|delete|post". Admin users bypass.
 */
abstract class ErpResource extends Resource
{
    protected static ?Module $module = null;

    protected static ?string $permissionKey = null;

    /** Extra actions shown in the permission tree for this page, e.g. [Permission::Post]. */
    protected static array $extraPermissions = [];

    protected static bool $isGloballySearchable = false;

    public static function getModule(): ?Module
    {
        return static::$module;
    }

    public static function getPermissionKey(): string
    {
        return static::$permissionKey
            ?? (static::$module?->value ?? 'general').'.'.Str::kebab(Str::pluralStudly(class_basename(static::getModel())));
    }

    /** @return array<Permission> */
    public static function getPermissionActions(): array
    {
        return array_merge([Permission::View, Permission::Create, Permission::Edit, Permission::Delete], static::$extraPermissions);
    }

    public static function userCan(Permission $permission): bool
    {
        return (bool) auth()->user()?->hasPermission(static::getPermissionKey().'.'.$permission->value);
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? $action->value : $action;

        return static::userCan(Permission::fromAbility($ability)) ? Response::allow() : Response::deny();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$module?->label() ?? parent::getNavigationGroup();
    }

    public static function getModelLabel(): string
    {
        return __(static::$modelLabel ?? parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel ?? parent::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string
    {
        return __(static::$navigationLabel ?? static::getPluralModelLabel());
    }
}
