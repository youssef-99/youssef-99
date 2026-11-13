<?php

namespace App\Filament\Resources\Sales\CustomerResource\Pages;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerResource;
use App\Services\CustomerService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function afterCreate(): void
    {
        try {
            CustomerService::afterSave($this->record);
        } catch (ErpException $e) {
            Notification::make()->danger()->title(__('Saved, but the customer account could not be created'))->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }
}
