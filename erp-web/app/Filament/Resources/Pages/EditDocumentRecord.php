<?php

namespace App\Filament\Resources\Pages;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Actions\DocumentActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

abstract class EditDocumentRecord extends EditRecord
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['post_now']);

        return $data;
    }

    protected function afterSave(): void
    {
        $service = static::getResource()::getDocumentService();
        $service::recalculate($this->record);

        if (! empty($this->data['post_now']) && ! static::getResource()::userCan(Permission::Post)) {
            Notification::make()->warning()->title(__('Saved, but you do not have permission to post'))->send();

            return;
        }

        if (! empty($this->data['post_now'])) {
            try {
                $service::post($this->record->refresh());
                Notification::make()->success()->title(__('Posted successfully'))->send();
            } catch (ErpException $e) {
                Notification::make()->danger()->title(__('Saved, but could not post'))->body($e->getMessage())->persistent()->send();
            }
        }
    }

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return array_filter([
            $resource::hasPage('view') ? ViewAction::make() : null,
            DocumentActions::post($resource::getDocumentService(), $resource),
            DocumentActions::print($resource::getPrintType()),
            DeleteAction::make(),
        ]);
    }

    protected function getRedirectUrl(): ?string
    {
        $resource = static::getResource();

        return $resource::hasPage('view') ? $resource::getUrl('view', ['record' => $this->record]) : $resource::getUrl('index');
    }
}
