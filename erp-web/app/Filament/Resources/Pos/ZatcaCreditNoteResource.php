<?php

namespace App\Filament\Resources\Pos;

use App\Filament\Resources\Pos\Shared\ZatcaDocumentTable;
use App\Filament\Resources\Pos\Shared\ZatcaResource;
use App\Filament\Resources\Pos\ZatcaCreditNoteResource\Pages\ListZatcaCreditNotes;
use App\Models\SalesDocument;
use App\Services\Zatca\ZatcaNoteService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ZATCA credit notes (الإشعارات الدائنة - هيئة الزكاة) — /sales/zatca-credit-notes.
 * Credit / debit notes against posted invoices: customer discounts and invoice corrections.
 */
class ZatcaCreditNoteResource extends ZatcaResource
{
    protected static ?string $permissionKey = 'sales.zatca-credit-notes';

    protected static array $zatcaTypes = ZatcaNoteService::TYPES;

    protected static ?string $modelLabel = 'ZATCA Credit Note';

    protected static ?string $pluralModelLabel = 'ZATCA Credit Notes';

    protected static ?string $slug = 'sales/zatca-credit-notes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-minus';

    protected static ?int $navigationSort = 92;

    public static function table(Table $table): Table
    {
        return ZatcaDocumentTable::configure($table, static::class, static::$zatcaTypes, [
            TextColumn::make('zatca_note_type')->label('Note Type')->badge()
                ->formatStateUsing(fn (?string $state) => __(ZatcaNoteService::NOTE_TYPES[$state] ?? (string) $state))
                ->color(fn (SalesDocument $record) => $record->type === ZatcaNoteService::DEBIT_NOTE ? 'warning' : 'info'),
            TextColumn::make('originalDocument.number')->label('Original Invoice No.')->searchable(),
            TextColumn::make('zatca_note_reason')->label('Reason')->limit(40)->toggleable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZatcaCreditNotes::route('/'),
        ];
    }
}
