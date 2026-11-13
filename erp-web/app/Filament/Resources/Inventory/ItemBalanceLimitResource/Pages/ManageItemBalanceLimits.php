<?php

namespace App\Filament\Resources\Inventory\ItemBalanceLimitResource\Pages;

use App\Filament\Resources\Inventory\ItemBalanceLimitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItemBalanceLimits extends ManageRecords
{
    protected static string $resource = ItemBalanceLimitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
