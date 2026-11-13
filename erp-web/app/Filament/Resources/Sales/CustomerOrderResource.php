<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Enums\Permission;
use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\CustomerOrderResource\Pages;
use App\Filament\Resources\Sales\Shared\ConvertToInvoiceAction;
use App\Filament\Resources\Sales\Shared\SalesDocumentForm;
use App\Filament\Resources\Sales\Shared\SalesDocumentTable;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Services\CustomerOrderService;
use App\Services\SalesDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Customer orders (طلبات العملاء) — /sales/customer-orders.
 * Workflow: draft → posted → approved / partially approved (per-line approved quantity) / rejected;
 * approved orders are converted to a sales invoice with the approved quantities.
 */
class CustomerOrderResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.customer-orders';

    protected static ?string $documentType = 'customer_order';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Customer Order';

    protected static ?string $pluralModelLabel = 'Customer Orders';

    protected static ?string $slug = 'sales/customer-orders';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return SalesDocumentForm::configure($schema, SalesDocumentType::CustomerOrder);
    }

    public static function table(Table $table): Table
    {
        return SalesDocumentTable::configure(
            $table,
            static::class,
            extraActions: static::workflowActions(),
            numberLabel: 'Order No.',
            statusType: SalesDocumentType::CustomerOrder,
        );
    }

    /** @return array<Action> */
    public static function workflowActions(): array
    {
        return [static::approveAction(), static::rejectAction(), ConvertToInvoiceAction::make('Convert to Invoice')];
    }

    /** Approve (اعتماد): enter the approved quantity of each line. */
    public static function approveAction(): Action
    {
        return Action::make('approveOrder')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (SalesDocument $record) => CustomerOrderService::canDecide($record) && static::userCan(Permission::Post))
            ->modalWidth('4xl')
            ->fillForm(fn (SalesDocument $record) => [
                'lines' => $record->lines()->with('item')->get()->mapWithKeys(fn (SalesDocumentLine $line) => ['line'.$line->id => [
                    'line_id' => $line->id,
                    'item' => $line->item?->label,
                    'quantity' => (float) $line->quantity,
                    'approved_qty' => (float) ($line->approved_qty ?? $line->quantity),
                ]])->all(),
            ])
            ->schema([
                Repeater::make('lines')
                    ->label('Items')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->table([
                        TableColumn::make(__('Item')),
                        TableColumn::make(__('Quantity')),
                        TableColumn::make(__('Approved Quantity'))->markAsRequired(),
                    ])
                    ->schema([
                        Hidden::make('line_id'),
                        TextInput::make('item')->label('Item')->readOnly(),
                        TextInput::make('quantity')->label('Quantity')->numeric()->readOnly(),
                        TextInput::make('approved_qty')->label('Approved Quantity')->numeric()->required()->minValue(0)
                            ->maxValue(fn (Get $get) => (float) $get('quantity')),
                    ]),
            ])
            ->action(function (array $data, SalesDocument $record, Action $action) {
                $quantities = collect($data['lines'] ?? [])->mapWithKeys(fn (array $l) => [(int) $l['line_id'] => $l['approved_qty']])->all();
                try {
                    $order = CustomerOrderService::approve($record, $quantities);
                    Notification::make()->success()->title($order->status === 'approved' ? __('Order approved') : __('Order partially approved'))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot approve'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    /** Reject (رفض) with a reason. */
    public static function rejectAction(): Action
    {
        return Action::make('rejectOrder')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (SalesDocument $record) => CustomerOrderService::canDecide($record) && static::userCan(Permission::Post))
            ->schema([
                Textarea::make('reason')->label('Rejection Reason')->required()->rows(3)->maxLength(1000),
            ])
            ->action(function (array $data, SalesDocument $record, Action $action) {
                try {
                    CustomerOrderService::reject($record, (string) $data['reason']);
                    Notification::make()->success()->title(__('Order rejected'))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot reject'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerOrders::route('/'),
            'create' => Pages\CreateCustomerOrder::route('/create'),
            'view' => Pages\ViewCustomerOrder::route('/{record}'),
            'edit' => Pages\EditCustomerOrder::route('/{record}/edit'),
        ];
    }
}
