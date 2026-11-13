<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\AgentResource\Pages\ManageAgents;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Agent;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Agents (الوكلاء) — /inventory/agents.
 */
class AgentResource extends ErpResource
{
    protected static ?string $model = Agent::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.agents';

    protected static ?string $modelLabel = 'Agent';

    protected static ?string $pluralModelLabel = 'Agents';

    protected static ?string $slug = 'inventory/agents';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Agent::class, label: 'Agent Code'),
            ...Fields::names('Agent Name'),
            TextInput::make('phone')->label('Phone')->tel()->maxLength(50),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Agent Code'),
                Columns::name('Agent Name'),
                TextColumn::make('phone')->label('Phone'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAgents::route('/'),
        ];
    }
}
