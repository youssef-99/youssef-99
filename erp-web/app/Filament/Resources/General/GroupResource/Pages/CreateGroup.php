<?php

namespace App\Filament\Resources\General\GroupResource\Pages;

use App\Filament\Resources\General\GroupResource;
use App\Filament\Resources\General\GroupResource\PermissionTree;
use Filament\Resources\Pages\CreateRecord;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['permissions'] = PermissionTree::toPermissions((array) ($data['tree'] ?? []));
        unset($data['tree']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
