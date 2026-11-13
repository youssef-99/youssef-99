<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\ActivityResource\Pages\ManageActivities;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Activity;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Business activities (الأنشطة) — /general/activities.
 */
class ActivityResource extends ErpResource
{
    protected static ?string $model = Activity::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.activities';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activities';

    protected static ?string $slug = 'general/activities';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Activity::class, label: 'Activity Code'),
            ...Fields::names('Activity Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Activity Code'),
                Columns::name('Activity Name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivities::route('/'),
        ];
    }
}
