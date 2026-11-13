<?php

namespace App\Filament\Resources\Inventory\Shared;

use App\Enums\StockDocumentType;
use App\Filament\Support\DocumentLines;
use App\Filament\Support\Fields;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared form for stock documents: adjustments, transfers and stocktake records.
 */
class StockDocumentForm
{
    public static function configure(Schema $schema, StockDocumentType $type): Schema
    {
        $isStocktake = $type->isStocktake();

        $header = [
            Fields::branch(),
            static::number($type),
            Fields::date(label: $isStocktake ? 'Stocktake Record Date' : 'Voucher Date'),
            Fields::warehouse(),
        ];

        if (in_array($type, [StockDocumentType::PositiveAdjustment, StockDocumentType::NegativeAdjustment, StockDocumentType::AnnualStocktake], true)) {
            $header[] = Fields::account(label: 'Account')->required()->default(fn () => Settings::account('inventory_adjustment'));
        }

        if ($type === StockDocumentType::OutboundTransfer) {
            array_push($header,
                Fields::branch('to_branch_id', 'toBranch', 'To Branch')
                    ->afterStateUpdated(fn ($state, Set $set) => $set('to_warehouse_id', $state ? Warehouse::query()->where('branch_id', $state)->value('id') : null)),
                Fields::warehouse('to_warehouse_id', 'toWarehouse', 'to_branch_id', 'To Warehouse')
                    ->default(null)
                    ->different('warehouse_id')
                    ->validationMessages(['different' => __('The destination warehouse must differ from the source warehouse.')]),
                Fields::relation('salesman_id', 'salesman', 'Salesman'),
            );
        }

        if ($type === StockDocumentType::PeriodicStocktake) {
            $header[] = Fields::relation('supplier_id', 'supplier', 'Supplier');
        }

        $header[] = Textarea::make('notes')->label('Notes')->rows(1)->columnSpanFull();

        $components = [Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema($header)];

        if ($isStocktake) {
            $components[] = Actions::make([static::loadItemsAction()])->key('stocktakeActions');
        }

        $components[] = match (true) {
            $isStocktake => DocumentLines::stockRepeater(stocktake: true)->label('Items'),
            in_array($type, [StockDocumentType::NegativeAdjustment, StockDocumentType::OutboundTransfer], true) => StockLines::outgoingRepeater(),
            default => DocumentLines::stockRepeater()->label('Items'),
        };

        if ($type !== StockDocumentType::PeriodicStocktake) {
            $components[] = Toggle::make('post_now')
                ->label($isStocktake ? 'Post Stocktake Record?' : 'Post Voucher?')
                ->dehydrated(false)
                ->default(false);
        }

        $components[] = DocumentLines::totals(withTax: false, percentDiscount: false);

        return $schema->columns(1)->components($components);
    }

    /** Voucher Code / Stocktake Record Code (blank = auto), unique per type and branch. */
    public static function number(StockDocumentType $type): TextInput
    {
        return Fields::number(label: $type->isStocktake() ? 'Stocktake Record Code' : 'Voucher Code')
            ->unique(StockDocument::class, 'number', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                ->where('type', $type->value)
                ->where('branch_id', $get('branch_id')));
    }

    /** Inbound transfers: header comes from the outbound transfer, lines are read-only. */
    public static function inbound(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema([
                Fields::relation('branch_id', 'branch', 'Branch')->disabled(),
                TextInput::make('number')->label('Voucher Code')->disabled(),
                Fields::date(label: 'Receipt Date'),
                Select::make('warehouse_id')->label('Warehouse')
                    ->relationship('warehouse', 'name_ar')
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->label)
                    ->disabled(),
                Fields::relation('to_branch_id', 'toBranch', 'From Branch')->disabled(),
                Select::make('to_warehouse_id')->label('From Warehouse')
                    ->relationship('toWarehouse', 'name_ar')
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->label)
                    ->disabled(),
                Select::make('related_document_id')->label('Outbound Transfer No.')
                    ->relationship('relatedDocument', 'number', fn (Builder $query) => $query->where('type', StockDocumentType::OutboundTransfer->value))
                    ->disabled(),
                Fields::relation('salesman_id', 'salesman', 'Salesman')->disabled(),
                Textarea::make('notes')->label('Notes')->rows(1)->columnSpanFull(),
            ]),
            DocumentLines::stockRepeater()->label('Items')->disabled()->addable(false)->deletable(false),
            Toggle::make('post_now')->label('Confirm Receipt?')->dehydrated(false)->default(false),
            DocumentLines::totals(withTax: false, percentDiscount: false)->disabled(),
        ]);
    }

    /** Fill the grid with every item / batch that has a balance in the warehouse. */
    public static function loadItemsAction(): Action
    {
        return Action::make('loadItems')
            ->label('Load Warehouse Items')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Replaces the grid with every item that has a balance in the warehouse; the counted quantity starts at the book balance.'))
            ->action(function (Get $get, Set $set) {
                if (! $get('warehouse_id')) {
                    Notification::make()->warning()->title(__('Select a warehouse.'))->send();

                    return;
                }
                $set('lines', StockLines::stocktakeLinesFor((int) $get('warehouse_id'), $get('date')));
                DocumentLines::updateTotals($get, $set);
            });
    }
}
