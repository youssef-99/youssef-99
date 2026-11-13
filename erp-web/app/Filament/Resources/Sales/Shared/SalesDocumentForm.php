<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Enums\SalesDocumentType;
use App\Filament\Support\DocumentLines;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Services\Calc\DocumentCalculator;
use App\Services\SalesDocumentService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared form for credit sales documents: quotations, customer orders, sales invoices and returns.
 */
class SalesDocumentForm
{
    public static function configure(Schema $schema, SalesDocumentType $type): Schema
    {
        $isReturn = $type->isReturn();
        [$numberLabel, $dateLabel] = static::numberAndDateLabels($type);

        $header = [
            Fields::branch(),
            Fields::number(label: $numberLabel),
            Fields::date(label: $dateLabel),
            Fields::warehouse(),
            Fields::relation('customer_id', 'customer', 'Customer')
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                    $customer = $state ? Customer::query()->find($state) : null;
                    $set('account_id', $customer?->account_id);
                    $set('salesman_id', $customer?->salesman_id);
                    $set('sales_policy_id', $customer?->sales_policy_id);
                    if ($customer?->payment_term_days) {
                        $set('due_date', Carbon::parse($get('date') ?? now())->addDays($customer->payment_term_days)->toDateString());
                    }
                }),
            Fields::account(label: 'Account')->required(),
            Fields::relation('salesman_id', 'salesman', 'Salesman'),
            Fields::relation('sales_policy_id', 'salesPolicy', 'Sales Policy'),
            DatePicker::make('due_date')->label('Due Date')->native(false)->displayFormat('d/m/Y')->visible(! $isReturn),
            TextInput::make('manual_no')->label('Manual Voucher No.'),
            Select::make('payment_method')->label('Payment Method')->options([
                'credit' => __('Credit'), 'cash' => __('Cash'), 'card' => __('Card'),
            ])->default('credit')->required(),
        ];

        if ($isReturn) {
            $originalType = static::originalTypeFor($type);
            $header[] = Select::make('original_document_id')
                ->label('Original Invoice No.')
                ->relationship('originalDocument', 'number', fn (Builder $query, Get $get) => $query
                    ->where('type', $originalType->value)
                    ->where('is_posted', true)
                    ->when($get('customer_id'), fn ($q, $c) => $q->where('customer_id', $c)))
                ->getOptionLabelFromRecordUsing(fn (SalesDocument $r) => $r->number.' - '.$r->date->format('d/m/Y').' ('.number_format((float) $r->grand_total, 2).')')
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Set $set, ?SalesDocument $record) => static::fillFromOriginal($state, $set, $record?->id));
        }

        $components = [
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema($header),
        ];

        if (in_array($type, [SalesDocumentType::Quotation, SalesDocumentType::CustomerOrder, SalesDocumentType::SalesInvoice], true)) {
            $components[] = static::workflowSection($type);
        }

        array_push($components,
            DocumentLines::scanner(),
            DocumentLines::salesRepeater(withReturnedQty: $isReturn),
            Section::make()->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(2),
                Toggle::make('post_now')->label(static::postNowLabel($type))->dehydrated(false)->default(false),
            ]),
            DocumentLines::totals(),
        );

        return $schema->columns(1)->components($components);
    }

    /** @return array{0: string, 1: string} number and date labels */
    public static function numberAndDateLabels(SalesDocumentType $type): array
    {
        return match ($type) {
            SalesDocumentType::Quotation => ['Quotation No.', 'Quotation Date'],
            SalesDocumentType::CustomerOrder => ['Order No.', 'Order Date'],
            SalesDocumentType::SalesReturn => ['Return No.', 'Return Date'],
            default => ['Invoice No.', 'Invoice Date'],
        };
    }

    public static function postNowLabel(SalesDocumentType $type): string
    {
        return match ($type) {
            SalesDocumentType::Quotation => 'Confirm?',
            SalesDocumentType::CustomerOrder => 'Post Order?',
            default => 'Post Invoice?',
        };
    }

    /** The invoice type a return refers to. */
    public static function originalTypeFor(SalesDocumentType $returnType): SalesDocumentType
    {
        return match ($returnType) {
            SalesDocumentType::PosReturn => SalesDocumentType::PosInvoice,
            SalesDocumentType::CashSalesReturn => SalesDocumentType::CashSalesInvoice,
            default => SalesDocumentType::SalesInvoice,
        };
    }

    /** Read-only workflow info on the view page: status, approval / rejection, conversion link. */
    protected static function workflowSection(SalesDocumentType $type): Section
    {
        return Section::make()
            ->columns(['default' => 1, 'md' => 4])
            ->visible(fn (string $operation, ?SalesDocument $record) => $operation === 'view' && $record
                && ($type !== SalesDocumentType::SalesInvoice || $record->source_document_id))
            ->schema([
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => SalesDocumentStatus::label($state, $type))
                    ->color(fn (?string $state) => SalesDocumentStatus::color($state))
                    ->visible($type !== SalesDocumentType::SalesInvoice),
                TextEntry::make('approved_at')->label('Approval Date')->dateTime('d/m/Y H:i')->placeholder('-')
                    ->visible($type === SalesDocumentType::CustomerOrder),
                TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('-')
                    ->visible(fn (?SalesDocument $record) => $record?->status === 'rejected'),
                TextEntry::make('converted_invoice')->label('Sales Invoice')
                    ->state(fn (?SalesDocument $record) => $record?->convertedDocuments()->value('number'))
                    ->placeholder('-')
                    ->visible($type !== SalesDocumentType::SalesInvoice),
                TextEntry::make('source_document')->label('Converted From')
                    ->state(fn (?SalesDocument $record) => $record?->sourceDocument ? $record->sourceDocument->typeEnum()->label().' #'.$record->sourceDocument->number : null)
                    ->visible($type === SalesDocumentType::SalesInvoice),
            ]);
    }

    /**
     * Copy the lines of the original invoice into a return, with the quantity still returnable
     * (sold minus earlier posted returns) as both the "Qty Sold" hint and the default quantity.
     */
    public static function fillFromOriginal(?int $id, Set $set, ?int $exceptReturnId = null): void
    {
        $original = $id ? SalesDocument::query()->with('lines')->find($id) : null;
        if (! $original) {
            return;
        }

        $returnable = SalesDocumentService::returnableQuantities($original, $exceptReturnId);
        $remaining = array_map(fn (array $r) => $r['available'], $returnable);

        $lines = [];
        foreach ($original->lines as $line) {
            $available = min((float) $line->quantity, $remaining[$line->item_id] ?? 0);
            if ($available <= 0) {
                continue;
            }
            $remaining[$line->item_id] -= $available;
            $lines[(string) Str::uuid()] = ['quantity' => $available, 'sold_qty' => (float) $line->quantity]
                + $line->only(['item_id', 'unit_id', 'item_batch_id', 'unit_price', 'discount_percent', 'tax_percent']);
        }
        $lines = DocumentLines::recalculatedLines($lines);
        $set('lines', $lines);
        $set('customer_id', $original->customer_id);
        $set('account_id', $original->account_id);
        $set('salesman_id', $original->salesman_id);
        $set('warehouse_id', $original->warehouse_id);
        $set('additional_discount_percent', $original->additional_discount_percent);
        $additionalDiscount = (float) $original->additional_discount_percent > 0 ? 0 : (float) $original->additional_discount;
        $set('additional_discount', $additionalDiscount);

        $totals = DocumentCalculator::totals($lines, $additionalDiscount, $original->additional_discount_percent);
        foreach (DocumentLines::TOTAL_FIELDS as $field) {
            $set($field, $totals[$field]);
        }
    }
}
