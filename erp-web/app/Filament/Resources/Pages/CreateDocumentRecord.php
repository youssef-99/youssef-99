<?php

namespace App\Filament\Resources\Pages;

use App\Exceptions\ErpException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for document resources (see IsDocumentResource):
 * sets `type`, recalculates totals after the lines are saved and
 * posts immediately when the form has a truthy `post_now` field.
 */
abstract class CreateDocumentRecord extends CreateRecord
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($type = static::getResource()::getDocumentType()) {
            $data['type'] = $type;
        }
        unset($data['post_now']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = static::getResource()::getDocumentService();
        $service::recalculate($this->record);

        if (! empty($this->data['post_now'])) {
            try {
                $service::post($this->record->refresh());
                Notification::make()->success()->title(__('Posted successfully'))->send();
            } catch (ErpException $e) {
                Notification::make()->danger()->title(__('Saved, but could not post'))->body($e->getMessage())->persistent()->send();
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();

        return $resource::hasPage('view') ? $resource::getUrl('view', ['record' => $this->record]) : $resource::getUrl('index');
    }
}
