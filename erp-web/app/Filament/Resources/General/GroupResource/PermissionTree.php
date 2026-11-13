<?php

namespace App\Filament\Resources\General\GroupResource;

use App\Support\PermissionRegistry;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The permissions checkbox tree on the user-group form (module > page > actions).
 *
 * Form state shape: tree.{module}.{pageField} => ["sales.customers.view", ...]
 * where pageField is a dot-free hash of the page permission key. Stored on the
 * group as a flat list of permission keys (see User::hasPermission()).
 */
class PermissionTree
{
    /**
     * Memoized trees per locale (labels are translated).
     *
     * @var array<string, array<string, array{label: string, pages: array<string, array{label: string, actions: array<string, string>}>}>>
     */
    protected static array $trees = [];

    /**
     * @return array<string, array{label: string, pages: array<string, array{label: string, actions: array<string, string>}>}>
     */
    public static function tree(): array
    {
        return static::$trees[app()->getLocale()] ??= PermissionRegistry::tree();
    }

    /** Forget the memoized tree (tests, locale switch). */
    public static function flush(): void
    {
        static::$trees = [];
    }

    /** Dot-free state key for a page permission key such as "sales.customers". */
    public static function fieldKey(string $pageKey): string
    {
        return 'p_'.substr(md5($pageKey), 0, 16);
    }

    /** @return array<string> every permission key currently offered by the tree */
    public static function knownPermissions(): array
    {
        return collect(static::tree())
            ->flatMap(fn (array $module) => collect($module['pages'])->flatMap(fn (array $page) => array_keys($page['actions'])))
            ->values()
            ->all();
    }

    /**
     * Flatten the form state into the list of selected permission keys (unknown values dropped).
     *
     * @param  array<string, array<string, array<string>|null>>  $state
     * @return array<string>
     */
    public static function toPermissions(array $state): array
    {
        $selected = collect($state)->flatten()->filter()->unique()->values()->all();

        return array_values(array_intersect(static::knownPermissions(), $selected));
    }

    /**
     * Build the form state from stored permission keys.
     *
     * @param  array<string>  $permissions
     * @return array<string, array<string, array<string>>>
     */
    public static function fromPermissions(array $permissions): array
    {
        $state = [];

        foreach (static::tree() as $moduleKey => $module) {
            foreach ($module['pages'] as $pageKey => $page) {
                $state[$moduleKey][static::fieldKey($pageKey)] = array_values(array_intersect(array_keys($page['actions']), $permissions));
            }
        }

        return $state;
    }

    /**
     * Selected keys plus stored keys the tree does not show right now (e.g. a page that is temporarily unavailable).
     *
     * @param  array<string>  $selected
     * @param  array<string>  $stored
     * @return array<string>
     */
    public static function merge(array $selected, array $stored): array
    {
        return array_values(array_unique(array_merge($selected, array_diff($stored, static::knownPermissions()))));
    }

    /**
     * @param  array<string>  $permissions
     */
    public static function isEverythingSelected(array $permissions): bool
    {
        $known = static::knownPermissions();

        return $known !== [] && array_diff($known, $permissions) === [];
    }

    /**
     * @return array<Component|Field>
     */
    public static function components(): array
    {
        $tree = static::tree();

        $components = [
            Checkbox::make('select_all')
                ->label('Select All')
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (Set $set, ?bool $state) use ($tree): void {
                    foreach ($tree as $moduleKey => $module) {
                        static::setModule($set, $moduleKey, $module, (bool) $state);
                        $set("module_all.{$moduleKey}", (bool) $state);
                    }
                })
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (static::toPermissions((array) ($get('tree') ?? [])) === []) {
                        $fail(__('Select at least one permission.'));
                    }
                }),
        ];

        foreach ($tree as $moduleKey => $module) {
            $pages = [
                Checkbox::make("module_all.{$moduleKey}")
                    ->label(__('Select All').' — '.$module['label'])
                    ->live()
                    ->dehydrated(false)
                    ->columnSpanFull()
                    ->afterStateUpdated(fn (Set $set, ?bool $state) => static::setModule($set, $moduleKey, $module, (bool) $state)),
            ];

            foreach ($module['pages'] as $pageKey => $page) {
                $pages[] = CheckboxList::make("tree.{$moduleKey}.".static::fieldKey($pageKey))
                    ->label($page['label'])
                    ->translateLabel(false)
                    ->options($page['actions'])
                    ->columns(min(count($page['actions']), 5))
                    ->gridDirection('row');
            }

            $components[] = Section::make($module['label'])
                ->collapsible()
                ->collapsed()
                ->compact()
                ->columns(2)
                ->schema($pages);
        }

        return $components;
    }

    /**
     * @param  array{label: string, pages: array<string, array{label: string, actions: array<string, string>}>}  $module
     */
    protected static function setModule(Set $set, string $moduleKey, array $module, bool $selected): void
    {
        foreach ($module['pages'] as $pageKey => $page) {
            $set("tree.{$moduleKey}.".static::fieldKey($pageKey), $selected ? array_keys($page['actions']) : []);
        }
    }
}
