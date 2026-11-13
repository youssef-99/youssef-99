<?php

namespace App\Filament\Resources\General\GroupResource\Pages;

use App\Filament\Resources\General\GroupResource;
use App\Filament\Resources\General\GroupResource\PermissionTree;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $permissions = (array) ($data['permissions'] ?? []);
        $data['tree'] = PermissionTree::fromPermissions($permissions);
        $data['select_all'] = PermissionTree::isEverythingSelected($permissions);
        $data['module_all'] = collect(PermissionTree::tree())
            ->map(fn (array $module): bool => collect($module['pages'])->flatMap(fn (array $page) => array_keys($page['actions']))->diff($permissions)->isEmpty())
            ->all();
        unset($data['permissions']);

        return $data;
    }

    /**
     * Keys of pages that are not in the tree right now are kept as they are.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['permissions'] = PermissionTree::merge(
            PermissionTree::toPermissions((array) ($data['tree'] ?? [])),
            (array) ($this->getRecord()->permissions ?? []),
        );
        unset($data['tree']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
