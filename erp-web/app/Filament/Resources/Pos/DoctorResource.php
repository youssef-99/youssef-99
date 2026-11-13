<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\DoctorResource\Pages\ManageDoctors;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Doctor;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Doctors (الأطباء) — /sales/doctors.
 */
class DoctorResource extends ErpResource
{
    protected static ?string $model = Doctor::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.doctors';

    protected static ?string $modelLabel = 'Doctor';

    protected static ?string $pluralModelLabel = 'Doctors';

    protected static ?string $slug = 'sales/doctors';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = 82;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Doctor::class, label: 'Doctor Code'),
            ...Fields::names('Doctor Name'),
            TextInput::make('specialty')->label('Specialty')->maxLength(255),
            TextInput::make('clinic')->label('Clinic')->maxLength(255),
            TextInput::make('phone')->label('Phone')->tel()->maxLength(50),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Doctor Code'),
                Columns::name('Doctor Name'),
                TextColumn::make('specialty')->label('Specialty')->searchable(),
                TextColumn::make('clinic')->label('Clinic')->searchable()->toggleable(),
                TextColumn::make('phone')->label('Phone'),
                TextColumn::make('sales_documents_count')->label('Prescriptions')->counts('salesDocuments')->sortable()->toggleable(),
                Columns::boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDoctors::route('/'),
        ];
    }
}
