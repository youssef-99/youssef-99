<?php

namespace App\Filament\Resources\Pos\PointsCustomerResource\RelationManagers;

use App\Filament\Support\Columns;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Points transactions (حركات النقاط) of a loyalty customer — read-only.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Points Transactions');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::date(),
                TextColumn::make('salesDocument.number')->label('Invoice No.')->placeholder('-'),
                TextColumn::make('description')->label('Description')->wrap(),
                TextColumn::make('points')->label('Points')->numeric(2)->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
            ])
            ->defaultSort('id', 'desc');
    }
}
