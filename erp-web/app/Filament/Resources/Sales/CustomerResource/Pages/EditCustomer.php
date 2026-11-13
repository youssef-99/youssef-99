<?php

namespace App\Filament\Resources\Sales\CustomerResource\Pages;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerResource;
use App\Services\CustomerService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function afterSave(): void
    {
        try {
            CustomerService::afterSave($this->record);
        } catch (ErpException $e) {
            Notification::make()->danger()->title(__('Saved, but the customer account could not be updated'))->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            CustomerResource::viewAttachmentsAction(),
            CustomerResource::deleteAction()->after(fn () => $this->redirect(CustomerResource::getUrl('index'))),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }
}
