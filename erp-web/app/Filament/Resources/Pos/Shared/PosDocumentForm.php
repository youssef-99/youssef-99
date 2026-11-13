<?php

namespace App\Filament\Resources\Pos\Shared;

use App\Enums\SalesDocumentType;
use App\Filament\Resources\Pos\Support\PosLines;
use App\Filament\Support\Fields;
use App\Models\PointsCustomer;
use App\Models\SalesDocument;
use App\Services\DocumentNumber;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * POS invoice (فاتورة نقاط البيع) and POS return (مرتجع نقاط البيع) forms.
 */
class PosDocumentForm
{
    public static function invoice(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 6])->schema([
                Fields::branch(),
                Fields::number(label: 'Invoice No.'),
                Fields::date(label: 'Invoice Date'),
                Fields::warehouse(),
                Fields::relation('doctor_id', 'doctor', 'Doctor'),
                static::pointsCustomer(),
            ]),
            PosLines::scanner(),
            PosLines::repeater(),
            Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                Textarea::make('notes')->label('Notes')->rows(2)->columnSpan(['md' => 2]),
                PosLines::pointsRedemption(),
                Hidden::make('points_discount')->default(0),
            ]),
            PosLines::totals(),
            PosLines::payment(),
            Section::make(__('Delivery'))->collapsed()->columns(['default' => 1, 'md' => 4])->schema([
                Toggle::make('is_external')->label('External Invoice?'),
                Toggle::make('is_pickup_from_branch')->label('Pickup from Branch?')->live(),
                Fields::relation('received_from_branch_id', 'receivedFromBranch', 'Received from Branch')
                    ->visible(fn (Get $get) => (bool) $get('is_pickup_from_branch')),
                Toggle::make('needs_issue_transfer')->label('Needs Issue Transfer?'),
            ]),
            Section::make()->columns(2)->schema([
                Toggle::make('post_now')->label('Post Invoice?')->dehydrated(false)->default(true),
                Toggle::make('print_invoice')->label('Print Invoice')->dehydrated(false)->default(false),
            ])->hiddenOn('view'),
        ]);
    }

    public static function return(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema([
                Fields::branch(),
                Fields::number(label: 'Return No.'),
                Fields::date(label: 'Return Date'),
                Fields::warehouse(),
                Select::make('original_document_id')
                    ->label('Original POS Invoice')
                    ->required()
                    ->relationship('originalDocument', 'number', fn (Builder $query, Get $get) => $query
                        ->where('type', SalesDocumentType::PosInvoice->value)
                        ->where('is_posted', true)
                        ->when($get('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
                        ->latest('id'))
                    ->getOptionLabelFromRecordUsing(fn (SalesDocument $r) => $r->number.' - '.$r->date->format('d/m/Y').' ('.number_format((float) $r->grand_total, 2).')')
                    ->searchable(['number'])
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => PosLines::fillFromOriginal($state ? (int) $state : null, $get, $set))
                    ->disabledOn('edit'),
                Fields::relation('doctor_id', 'doctor', 'Doctor'),
                static::pointsCustomer()->disabled()->dehydrated(),
            ]),
            PosLines::returnRepeater(),
            Section::make()->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(2),
                Toggle::make('post_now')->label('Post?')->dehydrated(false)->default(true)->hiddenOn('view'),
            ]),
            PosLines::totals(),
            Section::make(__('Refund'))->columns(['default' => 2, 'md' => 3])->visibleOn('view')->schema([
                TextInput::make('cash_amount')->label('Cash')->numeric()->readOnly(),
                TextInput::make('card_amount')->label('Visa / Mada')->numeric()->readOnly(),
                TextInput::make('payment_method')->label('Payment Method')->readOnly(),
            ]),
        ]);
    }

    public static function pointsCustomer(): Select
    {
        return Select::make('points_customer_id')
            ->label('Points Customer')
            ->relationship('pointsCustomer', 'name')
            ->getOptionLabelFromRecordUsing(fn (PointsCustomer $record) => $record->label)
            ->searchable(['code', 'name', 'mobile', 'delivery_code'])
            ->live()
            ->createOptionForm([
                TextInput::make('code')->label('Code')->required()->default(fn () => DocumentNumber::nextCode(PointsCustomer::class))->unique(PointsCustomer::class, 'code'),
                TextInput::make('name')->label('Customer Name')->required()->maxLength(255),
                TextInput::make('mobile')->label('Mobile')->required()->tel()->unique(PointsCustomer::class, 'mobile'),
                TextInput::make('delivery_code')->label('Delivery Code'),
            ]);
    }
}
