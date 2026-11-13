<?php

namespace App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Pos\PosSalesInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosSalesInvoices extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = PosSalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
