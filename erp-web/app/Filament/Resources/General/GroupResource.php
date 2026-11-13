<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\GroupResource\Pages\CreateGroup;
use App\Filament\Resources\General\GroupResource\Pages\EditGroup;
use App\Filament\Resources\General\GroupResource\Pages\ListGroups;
use App\Filament\Resources\General\GroupResource\PermissionTree;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Group;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * User groups (مجموعات المستخدمين) — /general/groups. A group is a role holding a list of permission keys.
 */
class GroupResource extends ErpResource
{
    protected static ?string $model = Group::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.groups';

    protected static ?string $modelLabel = 'User Group';

    protected static ?string $pluralModelLabel = 'User Groups';

    protected static ?string $slug = 'general/groups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Group Data'))
                ->columns(3)
                ->schema([
                    Fields::code(Group::class, label: 'Group Code')->readOnly(),
                    ...Fields::names('Group Name'),
                ]),
            Section::make(__('Permissions'))
                ->schema(PermissionTree::components()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users'))
            ->columns([
                Columns::code(label: 'Group Code'),
                Columns::name('Group Name'),
                TextColumn::make('users_count')->label('Users')->numeric()->alignEnd(),
                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(fn (Group $record): int => count($record->permissions ?? []))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Group $record): bool => $record->users_count > 0),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
