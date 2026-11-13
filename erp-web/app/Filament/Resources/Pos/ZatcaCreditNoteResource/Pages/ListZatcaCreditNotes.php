<?php

namespace App\Filament\Resources\Pos\ZatcaCreditNoteResource\Pages;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Resources\Pos\ZatcaCreditNoteResource;
use App\Filament\Support\DocumentLines;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Services\Zatca\ZatcaNoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class ListZatcaCreditNotes extends ListRecords
{
    protected static string $resource = ZatcaCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addCustomerDiscount')
                ->label('Add Customer Discount')
                ->icon('heroicon-o-receipt-percent')
                ->visible(fn () => ZatcaCreditNoteResource::userCan(Permission::Post))
                ->modalWidth('2xl')
                ->schema([
                    static::invoiceSelect()
                        ->afterStateUpdated(fn ($state, Set $set) => $set('remaining', $state ? ZatcaNoteService::creditableAmount(SalesDocument::query()->find($state)) : null)),
                    TextInput::make('remaining')->label('Remaining Invoice Amount (excl. VAT)')->numeric()->readOnly()->dehydrated(false),
                    TextInput::make('amount')->label('Discount Amount (excl. VAT)')->numeric()->required()->minValue(0.01),
                    DatePicker::make('date')->label('Date')->default(now())->required()->native(false),
                    Textarea::make('reason')->label('Reason')->required()->rows(2)->default(fn () => __('Discount granted to the customer')),
                ])
                ->action(function (array $data, Action $action) {
                    $this->runNoteAction($action, fn () => ZatcaNoteService::createCustomerDiscount(
                        SalesDocument::query()->findOrFail($data['invoice_id']), (float) $data['amount'], (string) $data['reason'], $data['date'],
                    ));
                }),
            Action::make('addInvoiceCorrection')
                ->label('Add Sales Invoice Correction')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => ZatcaCreditNoteResource::userCan(Permission::Post))
                ->modalWidth('4xl')
                ->schema([
                    static::invoiceSelect()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $invoice = $state ? SalesDocument::query()->with('lines')->find($state) : null;
                            $set('lines', $invoice ? $invoice->lines->mapWithKeys(fn ($l) => [(string) Str::uuid() => [
                                'item_id' => $l->item_id, 'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_percent' => (float) $l->tax_percent,
                            ]])->all() : []);
                        }),
                    Select::make('direction')->label('Note Type')->required()->default('credit')->options([
                        'credit' => __('Credit Note (decrease)'),
                        'debit' => __('Debit Note (increase)'),
                    ]),
                    DatePicker::make('date')->label('Date')->default(now())->required()->native(false),
                    Textarea::make('reason')->label('Reason')->required()->rows(2),
                    Repeater::make('lines')
                        ->label('Correction Items')
                        ->helperText(__('Quantity and unit price (excl. VAT) of the difference to credit or debit.'))
                        ->schema([
                            Select::make('item_id')->label('Item')->required()
                                ->options(fn (Get $get) => static::invoiceItems($get('../../invoice_id'))),
                            TextInput::make('quantity')->label('Quantity')->numeric()->required()->minValue(0.001),
                            TextInput::make('unit_price')->label('Unit Price')->numeric()->required()->minValue(0),
                            TextInput::make('tax_percent')->label('Tax %')->numeric()->readOnly(),
                        ])
                        ->columns(4)
                        ->defaultItems(0)
                        ->minItems(1),
                ])
                ->action(function (array $data, Action $action) {
                    $this->runNoteAction($action, fn () => ZatcaNoteService::createCorrection(
                        SalesDocument::query()->findOrFail($data['invoice_id']), (string) $data['direction'], (string) $data['reason'], array_values($data['lines'] ?? []), $data['date'],
                    ));
                }),
        ];
    }

    protected function runNoteAction(Action $action, \Closure $create): void
    {
        try {
            $note = $create();
            Notification::make()->success()->title(__('Note #:number created and posted', ['number' => $note->number]))->send();
        } catch (ErpException $e) {
            Notification::make()->danger()->title(__('Cannot post'))->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
    }

    public static function invoiceSelect(): Select
    {
        return Select::make('invoice_id')
            ->label('Sales Invoice')
            ->required()
            ->searchable()
            ->live()
            ->getSearchResultsUsing(fn (?string $search) => SalesDocument::query()
                ->whereIn('type', ZatcaNoteService::INVOICE_TYPES)
                ->where('is_posted', true)
                ->when($search, fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
                ->latest('id')->limit(50)->get()
                ->mapWithKeys(fn (SalesDocument $d) => [$d->id => static::invoiceLabel($d)])->all())
            ->getOptionLabelUsing(fn ($value) => ($d = SalesDocument::query()->find($value)) ? static::invoiceLabel($d) : null);
    }

    public static function invoiceLabel(SalesDocument $doc): string
    {
        return $doc->number.' - '.$doc->typeEnum()->label().' - '.$doc->date->format('d/m/Y').' ('.number_format((float) $doc->grand_total, 2).')';
    }

    /** @return array<int, string> */
    protected static function invoiceItems(mixed $invoiceId): array
    {
        if (! $invoiceId) {
            return [];
        }

        return Item::query()
            ->whereIn('id', SalesDocument::query()->find($invoiceId)?->lines()->pluck('item_id') ?? [])
            ->get()
            ->mapWithKeys(fn (Item $i) => [$i->id => DocumentLines::itemLabel($i)])
            ->all();
    }
}
