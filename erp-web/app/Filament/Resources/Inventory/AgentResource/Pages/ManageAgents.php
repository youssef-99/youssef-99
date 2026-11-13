<?php

namespace App\Filament\Resources\Inventory\AgentResource\Pages;

use App\Filament\Resources\Inventory\AgentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAgents extends ManageRecords
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
