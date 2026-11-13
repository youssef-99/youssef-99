<?php

namespace App\Support;

use App\Enums\Module;
use Filament\Facades\Filament;

/**
 * Builds the permission tree (module > page > actions) from every registered
 * ERP resource and page. Used by the Group form.
 */
class PermissionRegistry
{
    /**
     * @return array<string, array{label: string, pages: array<string, array{label: string, actions: array<string,string>}>}>
     */
    public static function tree(): array
    {
        $panel = Filament::getPanel('admin');
        $classes = array_merge($panel->getResources(), $panel->getPages());
        $tree = [];

        foreach (Module::cases() as $module) {
            $tree[$module->value] = ['label' => $module->label(), 'pages' => []];
        }

        foreach ($classes as $class) {
            if (! method_exists($class, 'getPermissionKey') || ! method_exists($class, 'getModule')) {
                continue;
            }

            $module = $class::getModule() ?? Module::General;
            $key = $class::getPermissionKey();
            $label = method_exists($class, 'getNavigationLabel') ? $class::getNavigationLabel() : $key;

            $actions = [];
            foreach ($class::getPermissionActions() as $permission) {
                $actions["{$key}.{$permission->value}"] = $permission->label();
            }

            $tree[$module->value]['pages'][$key] = ['label' => $label, 'actions' => $actions];
        }

        return array_filter($tree, fn ($node) => $node['pages'] !== []);
    }

    /** @return array<string> every permission key */
    public static function all(): array
    {
        return collect(static::tree())->flatMap(fn ($m) => collect($m['pages'])->flatMap(fn ($p) => array_keys($p['actions'])))->values()->all();
    }
}
