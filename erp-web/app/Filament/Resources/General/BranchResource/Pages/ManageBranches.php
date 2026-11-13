<?php

namespace App\Filament\Resources\General\BranchResource\Pages;

use App\Filament\Resources\General\BranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBranches extends ManageRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
