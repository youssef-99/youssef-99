<?php

namespace App\Filament\Concerns;

use App\Enums\Module;
use App\Enums\Permission;
use Illuminate\Support\Str;

/**
 * For custom Filament pages (reports, tools). Set `protected static ?Module $module` and
 * optionally `protected static ?string $permissionKey`. Access requires "{key}.view".
 */
trait HasErpPermission
{
    public static function getModule(): ?Module
    {
        return static::$module ?? null;
    }

    public static function getPermissionKey(): string
    {
        return static::$permissionKey ?? ((static::$module ?? Module::General)->value.'.'.Str::kebab(class_basename(static::class)));
    }

    /** @return array<Permission> */
    public static function getPermissionActions(): array
    {
        return [Permission::View];
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission(static::getPermissionKey().'.view');
    }

    public static function getNavigationGroup(): ?string
    {
        return (static::$module ?? null)?->label() ?? static::$navigationGroup;
    }

    public static function getNavigationLabel(): string
    {
        return __(static::$navigationLabel ?? static::$title ?? Str::headline(class_basename(static::class)));
    }

    public function getTitle(): string
    {
        return __(static::$title ?? static::getNavigationLabel());
    }
}
