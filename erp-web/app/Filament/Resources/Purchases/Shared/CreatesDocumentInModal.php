<?php

namespace App\Filament\Resources\Purchases\Shared;

use Filament\Actions\CreateAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * For list pages of documents whose header is added in a modal (purchase orders, cash invoices):
 * the modal saves the header, then opens the edit page where the item lines are entered.
 * The resource must define `public static function headerForm(Schema $schema): Schema` and may define
 * `public static function afterModalCreate(Model $record): void`.
 */
trait CreatesDocumentInModal
{
    protected function documentCreateAction(): CreateAction
    {
        $resource = static::getResource();

        return CreateAction::make()
            ->schema(fn (Schema $schema): Schema => $resource::headerForm($schema))
            ->mutateDataUsing(fn (array $data): array => ['type' => $resource::getDocumentType()] + $data)
            ->modalWidth(Width::FourExtraLarge)
            ->createAnother(false)
            ->after(function (Model $record) use ($resource): void {
                if (method_exists($resource, 'afterModalCreate')) {
                    $resource::afterModalCreate($record);
                }
            })
            ->successRedirectUrl(fn (Model $record): string => $resource::getUrl('edit', ['record' => $record]));
    }
}
