<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Login with username OR email, one active session per user.
 */
class Login extends \Filament\Auth\Pages\Login
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('User Name or Email'))
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);

        return [
            filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username' => $login,
            'password' => $data['password'],
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        if (config('erp.single_session')) {
            $data = $this->form->getState();
            $credentials = $this->getCredentialsFromFormData($data);
            $field = array_key_first($credentials);
            $user = User::query()->where($field, $credentials[$field])->first();

            if ($user && Hash::check($credentials['password'], $user->password)
                && $user->otherActiveSessions(session()->getId())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'data.email' => __('This User is already logged in'),
                ]);
            }
        }

        return parent::authenticate();
    }
}
