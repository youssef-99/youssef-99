<?php

namespace App\Filament\Resources\Purchases\Shared;

use App\Enums\PurchaseDocumentType;
use App\Filament\Support\DocumentLines;
use App\Filament\Support\Fields;
use App\Models\Accreditation;
use App\Models\Currency;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Services\Calc\DocumentCalculator;
use App\Services\PurchaseDocumentService;
use App\Services\Settings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared form for every purchase document: purchase orders, invoices, returns, foreign purchase
 * invoices and the cash purchase invoices / returns of the Cash Transactions module.
 */
class PurchaseDocumentForm
{
    /**
     * @param  bool  $headerOnly  modal "add" (purchase orders, cash documents): lines are added after saving
     */
    public static function configure(Schema $schema, PurchaseDocumentType $type, bool $headerOnly = false): Schema
    {
        $header = static::header($type);

        if ($headerOnly) {
            return $schema->columns(['default' => 1, 'md' => 2])->components($header);
        }

        $isInvoice = ! $type->isReturn() && $type !== PurchaseDocumentType::PurchaseOrder;
        $footer = [Textarea::make('notes')->label('Notes')->rows(2)];
        if ($type->isPostable()) {
            $footer[] = Toggle::make('post_now')->label($type->isReturn() ? 'Post Return?' : 'Post Invoice?')->dehydrated(false)->default(false)->hiddenOn('view');
        }

        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema($header),
            DocumentLines::scanner('purchase'),
            PurchaseLines::repeater(full: $isInvoice),
            Section::make()->columns(2)->schema($footer),
            PurchaseLines::totals(),
        ]);
    }

    /** @return array<int, mixed> */
    public static function header(PurchaseDocumentType $type): array
    {
        $isOrder = $type === PurchaseDocumentType::PurchaseOrder;
        $isForeign = $type === PurchaseDocumentType::ForeignPurchaseInvoice;
        $isReturn = $type->isReturn();
        $isCash = $type->isCash();
        $hasPaymentTerms = ! $isOrder && ! $isReturn && ! $isCash;

        $fields = [
            Fields::branch(),
            Fields::number(label: match (true) {
                $isOrder => 'Purchase Order No.',
                $isReturn => 'Return No.',
                default => 'Invoice No.',
            }),
            Fields::date(label: match (true) {
                $isOrder => 'Purchase Order Date',
                $isReturn => 'Return Date',
                default => 'Invoice Date',
            })->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => static::updateDueDate($get, $set)),
        ];

        if ($isCash) {
            $fields[] = Fields::account(label: 'Account', modifyQuery: fn (Builder $query) => $query->whereIn('type', ['cash', 'bank']))
                ->default(fn () => Settings::account('cash'))
                ->required();
            $fields[] = Fields::relation('cash_supplier_id', 'cashSupplier', 'Cash Supplier');
            $fields[] = TextInput::make('supplier_invoice_no')->label('Cash Supplier Invoice No.')->maxLength(50);
        } else {
            $fields[] = Fields::warehouse();
            $fields[] = Fields::relation('supplier_id', 'supplier', 'Supplier')
                ->required(! $isOrder)
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($hasPaymentTerms, $isForeign) {
                    $supplier = $state ? Supplier::query()->find($state) : null;
                    $set('account_id', $supplier?->account_id);
                    if ($hasPaymentTerms) {
                        $set('payment_period_days', (int) ($supplier?->payment_term_days ?? 0));
                        static::updateDueDate($get, $set);
                    }
                    if ($isForeign && $supplier?->currency_id) {
                        $set('currency_id', $supplier->currency_id);
                        $set('exchange_rate', (float) Currency::query()->whereKey($supplier->currency_id)->value('exchange_rate') ?: 1);
                    }
                });
            $fields[] = Fields::account(label: 'Account')->required();
            if (! $isOrder) {
                $fields[] = TextInput::make('supplier_invoice_no')->label('Supplier Invoice No.')->maxLength(50);
            }
        }

        if ($hasPaymentTerms) {
            array_push($fields,
                DatePicker::make('receipt_date')->label('Receipt Date')->default(now())->required()->native(false)->displayFormat('d/m/Y'),
                TextInput::make('payment_period_days')->label('Payment Period')->numeric()->integer()->minValue(0)->default(0)->required()->suffix(__('days'))
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => static::updateDueDate($get, $set)),
                DatePicker::make('due_date')->label('Due Date')->default(now())->required()->native(false)->displayFormat('d/m/Y'),
            );
        }

        if ($isReturn) {
            $invoiceTypes = $isCash
                ? [PurchaseDocumentType::CashPurchaseInvoice->value]
                : [PurchaseDocumentType::PurchaseInvoice->value, PurchaseDocumentType::ForeignPurchaseInvoice->value];

            $fields[] = Select::make('reference_type')
                ->label('Reference Type')
                ->options(['invoice' => __('Against Invoice'), 'free' => __('Without Reference')])
                ->default($isCash ? 'free' : 'invoice')
                ->required()
                ->live();
            $fields[] = Select::make('original_document_id')
                ->label('Original Invoice No.')
                ->relationship('originalDocument', 'number', fn (Builder $query, Get $get) => $query
                    ->whereIn('type', $invoiceTypes)
                    ->where('is_posted', true)
                    ->when($get('supplier_id'), fn ($q, $s) => $q->where('supplier_id', $s)))
                ->getOptionLabelFromRecordUsing(fn (PurchaseDocument $r) => $r->number.' - '.$r->date->format('d/m/Y').' ('.number_format((float) $r->grand_total, 2).')')
                ->searchable()
                ->visible(fn (Get $get) => $get('reference_type') === 'invoice')
                ->required(fn (Get $get) => $get('reference_type') === 'invoice')
                ->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set) => static::fillFromOriginal($state ? (int) $state : null, $get, $set));
        }

        if ($isForeign) {
            array_push($fields,
                Select::make('accreditation_id')
                    ->label('Letter of Credit')
                    ->relationship('accreditation', 'number', fn (Builder $query) => $query->where('status', 'open'))
                    ->getOptionLabelFromRecordUsing(fn (Accreditation $r) => $r->label)
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $lc = $state ? Accreditation::query()->find($state) : null;
                        if ($lc?->supplier_id) {
                            $set('supplier_id', $lc->supplier_id);
                            $set('account_id', Supplier::query()->whereKey($lc->supplier_id)->value('account_id'));
                        }
                        if ($lc?->currency_id) {
                            $set('currency_id', $lc->currency_id);
                            $set('exchange_rate', (float) Currency::query()->whereKey($lc->currency_id)->value('exchange_rate') ?: 1);
                        }
                    })
                    ->helperText(__('When selected, the L/C account is credited instead of the supplier.')),
                Fields::relation('currency_id', 'currency', 'Currency')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('exchange_rate', (float) Currency::query()->whereKey($state)->value('exchange_rate') ?: 1)),
                TextInput::make('exchange_rate')->label('Exchange Rate')->numeric()->required()->minValue(0.000001)->default(1),
                Fields::money('shipping_cost', 'Shipping Cost')->minValue(0),
                Fields::money('purchase_expenses', 'Purchase Expenses')->minValue(0)
                    ->helperText(__('Shipping cost and purchase expenses are allocated to the items cost by value.')),
            );
        }

        if ($isOrder || $isCash) {
            $fields[] = Textarea::make('notes')->label('Notes')->rows(2)->columnSpanFull()->visibleOn('create');
        }

        $fields[] = TextInput::make('voucher_no')->label('Voucher No.')
            ->formatStateUsing(fn (?PurchaseDocument $record) => $record?->journalEntry?->number)
            ->dehydrated(false)->disabled()->visibleOn('view');

        if (! $isOrder && ! $isReturn) {
            $fields[] = TextInput::make('rasd_status')->label('Rasd Status')
                ->formatStateUsing(fn (?string $state) => $state ? __(Str::headline($state)) : null)
                ->dehydrated(false)->disabled()->visibleOn('view');
        }

        return $fields;
    }

    public static function updateDueDate(Get $get, Set $set): void
    {
        if ($get('payment_period_days') === null || ! $get('date')) {
            return;
        }

        $set('due_date', Carbon::parse($get('date'))->addDays((int) $get('payment_period_days'))->toDateString());
    }

    /** Copy the still-returnable lines of the original invoice into the return. */
    public static function fillFromOriginal(?int $id, Get $get, Set $set): void
    {
        $original = $id ? PurchaseDocument::query()->with('lines')->find($id) : null;
        if (! $original) {
            return;
        }

        $available = PurchaseDocumentService::returnableQuantities($original);
        $lines = [];
        foreach ($original->lines as $line) {
            $qty = min((float) $line->quantity, (float) ($available[$line->item_id] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $row = $line->only(['item_id', 'unit_id', 'batch_no', 'unit_price', 'discount_percent', 'discount2_percent', 'tax_percent']);
            $row['expiry_date'] = $line->expiry_date?->toDateString();
            $row['quantity'] = $qty;
            $row['bonus_qty'] = 0;
            $row['bonus_percent'] = 0;
            $lines[(string) Str::uuid()] = array_merge($row, array_intersect_key(DocumentCalculator::line($row), array_flip(['gross', 'discount', 'discount2', 'tax', 'net', 'unit_net'])));
        }

        $set('lines', $lines);
        $set('supplier_id', $original->supplier_id);
        $set('cash_supplier_id', $original->cash_supplier_id);
        $set('account_id', $original->account_id);
        $set('warehouse_id', $original->warehouse_id);
        $set('supplier_invoice_no', $original->supplier_invoice_no);
        DocumentLines::updateTotals($get, $set);
    }
}
