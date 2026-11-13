<?php

namespace App\Filament\Resources\Pos\PosSalesReturnResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Pos\PosSalesReturnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosSalesReturns extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PosSalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
