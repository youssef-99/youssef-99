<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Enums\Permission;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\UserResource\Pages\CreateUser;
use App\Filament\Resources\General\UserResource\Pages\EditUser;
use App\Filament\Resources\General\UserResource\Pages\ListUsers;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

/**
 * Users (المستخدمين) — /general/users. Each user belongs to one group (role) and has a default branch.
 */
class UserResource extends ErpResource
{
    protected static ?string $model = User::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    protected static ?string $slug = 'general/users';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('User Data'))
                ->columns(3)
                ->schema([
                    Fields::code(User::class, label: 'User Code'),
                    TextInput::make('username')
                        ->label('Username')
                        ->required()
                        ->maxLength(50)
                        ->regex('/^[A-Za-z0-9._-]+$/')
                        ->validationMessages(['regex' => __('Use English letters, digits, dot, dash or underscore only.')])
                        ->unique(User::class, 'username', ignoreRecord: true),
                    TextInput::make('name')->label('Full Name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),
                    TextInput::make('phone')->label('Phone')->tel()->maxLength(50),
                    Fields::relation('group_id', 'group', 'Group')
                        ->required()
                        // Non-admins cannot move themselves into another (more privileged) group.
                        ->disabled(fn (?User $record): bool => (bool) $record?->is(auth()->user()) && ! auth()->user()?->is_admin),
                    Fields::relation('default_branch_id', 'defaultBranch', 'Default Branch')->required(),
                    Select::make('locale')
                        ->label('Language')
                        ->options(fn (): array => config('erp.locales'))
                        ->default('ar')
                        ->required(),
                    Fields::active()
                        ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                    Toggle::make('is_admin')
                        ->label('System Administrator?')
                        ->helperText(__('Administrators have every permission regardless of their group.'))
                        ->visible(fn (): bool => (bool) auth()->user()?->is_admin)
                        ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                ]),
            Section::make(__('Password'))
                ->columns(2)
                ->description(fn (string $operation): ?string => $operation === 'edit' ? __('Leave blank to keep the current password.') : null)
                ->schema(static::passwordFields()),
        ]);
    }

    /**
     * Password + confirmation. Required on create, optional on edit (blank keeps the current one).
     *
     * @return array<TextInput>
     */
    public static function passwordFields(bool $alwaysRequired = false): array
    {
        return [
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->rule(Password::default())
                ->required(fn (string $operation): bool => $alwaysRequired || $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->confirmed()
                ->autocomplete('new-password'),
            TextInput::make('password_confirmation')
                ->label('Confirm Password')
                ->password()
                ->revealable()
                ->required(fn (string $operation, Get $get): bool => $alwaysRequired || $operation === 'create' || filled($get('password')))
                ->dehydrated(false)
                ->autocomplete('new-password'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['group', 'defaultBranch']))
            ->columns([
                Columns::code(label: 'User Code'),
                TextColumn::make('username')->label('Username')->searchable()->sortable(),
                TextColumn::make('name')->label('Full Name')->searchable()->sortable(),
                Columns::name('Group', 'group'),
                Columns::name('Default Branch', 'defaultBranch'),
                TextColumn::make('email')->label('Email')->searchable(),
                Columns::boolean(),
            ])
            ->filters([Filters::relation('group_id', 'group', 'Group')])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    static::resetPasswordAction(),
                    static::terminateSessionsAction(),
                    DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
                ]),
            ])
            ->defaultSort('code');
    }

    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset Password')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (User $record): bool => static::userCan(Permission::Edit) && (! $record->is_admin || (bool) auth()->user()?->is_admin))
            ->modalWidth('lg')
            ->schema(static::passwordFields(alwaysRequired: true))
            ->action(function (User $record, array $data): void {
                $record->update(['password' => $data['password']]);

                if (! $record->is(auth()->user())) {
                    $record->terminateSessions();
                }

                Notification::make()->success()->title(__('Password changed'))->send();
            });
    }

    public static function terminateSessionsAction(): Action
    {
        return Action::make('terminateSessions')
            ->label('Terminate Sessions')
            ->icon('heroicon-o-power')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('The user will be logged out from every device.'))
            ->visible(fn (User $record): bool => static::userCan(Permission::Edit) && ! $record->is(auth()->user()) && (! $record->is_admin || (bool) auth()->user()?->is_admin))
            ->action(function (User $record): void {
                $count = $record->terminateSessions();

                Notification::make()->success()->title(__(':count sessions terminated', ['count' => $count]))->send();
            });
    }

    /** Only admins may edit or delete admin users. */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && (! $record->is_admin || (bool) auth()->user()?->is_admin);
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && (! $record->is_admin || (bool) auth()->user()?->is_admin);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/add'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
