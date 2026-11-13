<?php

namespace App\Filament\Resources\Sales\CustomerResource\Pages;

use App\Filament\Resources\Sales\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * View Customer (عرض العميل): the form read-only, plus Last Reconciliation Date and the accounts grid.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            CustomerResource::viewAttachmentsAction(),
            Action::make('cancel')->label(__('Cancel'))->color('gray')->url(CustomerResource::getUrl('index')),
        ];
    }
}
