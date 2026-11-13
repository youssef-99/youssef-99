<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\StakeholderResource\Pages\ManageStakeholders;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Stakeholder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Stakeholders / partners (أصحاب المصلحة) — /general/stakeholders.
 */
class StakeholderResource extends ErpResource
{
    protected static ?string $model = Stakeholder::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.stakeholders';

    protected static ?string $modelLabel = 'Stakeholder';

    protected static ?string $pluralModelLabel = 'Stakeholders';

    protected static ?string $slug = 'general/stakeholders';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 85;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Stakeholder::class, label: 'Stakeholder Code'),
            Fields::percent('share_percent', 'Share %'),
            ...Fields::names('Stakeholder Name'),
            TextInput::make('phone')->label('Phone')->tel()->maxLength(50),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Stakeholder Code'),
                Columns::name('Stakeholder Name'),
                TextColumn::make('share_percent')->label('Share %')->numeric(2)->suffix('%')->alignEnd()->sortable(),
                TextColumn::make('phone')->label('Phone'),
                TextColumn::make('email')->label('Email'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStakeholders::route('/'),
        ];
    }
}
