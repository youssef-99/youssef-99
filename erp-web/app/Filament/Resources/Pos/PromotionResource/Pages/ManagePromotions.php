<?php

namespace App\Filament\Resources\Pos\PromotionResource\Pages;

use App\Filament\Resources\Pos\PromotionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePromotions extends ManageRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
