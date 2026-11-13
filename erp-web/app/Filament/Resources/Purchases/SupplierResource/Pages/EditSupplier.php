<?php

namespace App\Filament\Resources\Purchases\SupplierResource\Pages;

use App\Exceptions\ErpException;
use App\Filament\Resources\Purchases\SupplierResource;
use App\Services\SupplierService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function afterSave(): void
    {
        try {
            SupplierService::afterSave($this->record);
        } catch (ErpException $e) {
            Notification::make()->danger()->title(__('Saved, but the account could not be updated'))->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), SupplierResource::deleteAction()];
    }

    protected function getRedirectUrl(): string
    {
        return SupplierResource::getUrl('view', ['record' => $this->record]);
    }
}
