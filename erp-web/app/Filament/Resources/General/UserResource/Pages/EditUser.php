<?php

namespace App\Filament\Resources\General\UserResource\Pages;

use App\Filament\Resources\General\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property User $record
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::terminateSessionsAction(),
            DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
        ];
    }

    /** A deactivated user is logged out everywhere. */
    protected function afterSave(): void
    {
        if (! $this->record->is_active && ! $this->record->is(auth()->user())) {
            $this->record->terminateSessions();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
