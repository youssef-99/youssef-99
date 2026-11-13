<?php

namespace App\Filament\Resources\General\GroupResource\Pages;

use App\Filament\Resources\General\GroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
