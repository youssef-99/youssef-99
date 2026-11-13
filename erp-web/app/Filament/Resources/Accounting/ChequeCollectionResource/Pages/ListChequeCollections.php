<?php

namespace App\Filament\Resources\Accounting\ChequeCollectionResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\ChequeCollectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChequeCollections extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = ChequeCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
