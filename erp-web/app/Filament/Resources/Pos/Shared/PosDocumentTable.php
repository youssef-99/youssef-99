<?php

namespace App\Filament\Resources\Pos\Shared;

use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Services\PosService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * POS invoices / returns list: Invoice No., Date, Branch, Tax, Net After Tax, POS flags and the row actions
 * View, Edit (draft), Print A4, Print 80mm, Post, Unpost, Delete (draft).
 */
class PosDocumentTable
{
    public static function configure(Table $table, string $resource, bool $isReturn = false): Table
    {
        $columns = [
            TextColumn::make('number')->label($isReturn ? 'Return No.' : 'Invoice No.')->searchable()->sortable(),
            Columns::date(label: $isReturn ? 'Return Date' : 'Invoice Date'),
            TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name)->toggleable(),
        ];

        if ($isReturn) {
            $columns[] = TextColumn::make('originalDocument.number')->label('Original POS Invoice')->searchable();
        }

        array_push($columns,
            Columns::money('tax_total', 'Tax'),
            Columns::money('grand_total', 'Net After Tax'),
        );

        if (! $isReturn) {
            array_push($columns,
                Columns::boolean('is_external', 'External Invoice?'),
                TextColumn::make('receivedFromBranch.name_ar')->label('Received from Branch')->formatStateUsing(fn ($record) => $record->receivedFromBranch?->name)->placeholder('-'),
                Columns::boolean('is_paid', 'Paid?'),
                Columns::boolean('needs_issue_transfer', 'Needs Issue Transfer?'),
            );
        }

        $columns[] = Columns::posted();

        return $table
            ->columns($columns)
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filter::make('number')
                    ->label($isReturn ? 'Return No.' : 'Invoice No.')
                    ->schema([TextInput::make('number')->label($isReturn ? 'Return No.' : 'Invoice No.')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['number'] ?? null, fn ($q, $n) => $q->where('number', 'like', "%{$n}%"))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DocumentActions::print('sales', 'printA4', 'Print A4'),
                    static::print80mm(),
                    DocumentActions::post(PosService::class, $resource),
                    DocumentActions::unpost(PosService::class, $resource),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function print80mm(): Action
    {
        return Action::make('print80mm')
            ->label('Print 80mm')
            ->icon('heroicon-o-receipt-percent')
            ->color('gray')
            ->url(fn (Model $record) => static::receiptUrl($record))
            ->openUrlInNewTab();
    }

    public static function receiptUrl(Model $record): string
    {
        return route('print', ['type' => 'sales', 'id' => $record->getKey()]).'?layout=80mm';
    }
}
