<?php

namespace App\Enums;

/**
 * Actions that can be granted per page. A permission key is "{page-key}.{action}",
 * e.g. "sales.customers.create".
 */
enum Permission: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Post = 'post';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View'),
            self::Create => __('Add'),
            self::Edit => __('Edit'),
            self::Delete => __('Delete'),
            self::Post => __('Post'),
        };
    }

    /** Map a Filament/Laravel policy ability to an ERP action. */
    public static function fromAbility(string $ability): self
    {
        return match ($ability) {
            'viewAny', 'view' => self::View,
            'create', 'replicate' => self::Create,
            'update', 'reorder' => self::Edit,
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => self::Delete,
            'post' => self::Post,
            default => self::View,
        };
    }
}
