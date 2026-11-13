<?php

namespace App\Filament\Resources\Sales\MarketingRepResource\Pages;

use App\Filament\Resources\Sales\MarketingRepResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMarketingReps extends ManageRecords
{
    protected static string $resource = MarketingRepResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
