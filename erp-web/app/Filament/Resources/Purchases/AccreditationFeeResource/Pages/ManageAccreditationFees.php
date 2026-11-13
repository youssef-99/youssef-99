<?php

namespace App\Filament\Resources\Purchases\AccreditationFeeResource\Pages;

use App\Filament\Resources\Purchases\AccreditationFeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAccreditationFees extends ManageRecords
{
    protected static string $resource = AccreditationFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
