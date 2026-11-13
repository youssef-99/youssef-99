<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Enums\SalesDocumentType;
use App\Filament\Support\DocumentLines;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Models\SalesDocument;
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

/**
 * Shared form for credit sales documents: quotations, customer orders, sales invoices and returns.
 */
class SalesDocumentForm
{
    public static function configure(Schema $schema, SalesDocumentType $type): Schema
    {
        $isReturn = $type->isReturn();

        $header = [
            Fields::branch(),
            Fields::number(label: $type === SalesDocumentType::Quotation ? 'Quotation No.' : 'Invoice No.'),
            Fields::date(label: $type === SalesDocumentType::Quotation ? 'Quotation Date' : 'Invoice Date'),
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
                        $set('due_date', \Illuminate\Support\Carbon::parse($get('date') ?? now())->addDays($customer->payment_term_days)->toDateString());
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
            $header[] = Select::make('original_document_id')
                ->label('Original Invoice No.')
                ->relationship('originalDocument', 'number', fn (Builder $q, Get $get) => $q
                    ->where('type', SalesDocumentType::SalesInvoice->value)
                    ->where('is_posted', true)
                    ->when($get('customer_id'), fn ($q, $c) => $q->where('customer_id', $c)))
                ->getOptionLabelFromRecordUsing(fn (SalesDocument $r) => $r->number.' - '.$r->date->format('d/m/Y').' ('.number_format((float) $r->grand_total, 2).')')
                ->searchable()
                ->live()
                ->afterStateUpdated(fn ($state, Set $set) => static::fillFromOriginal($state, $set));
        }

        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema($header),
            DocumentLines::scanner(),
            DocumentLines::salesRepeater(),
            Section::make()->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(2),
                Toggle::make('post_now')->label($type->isPostable() ? 'Post Invoice?' : 'Confirm?')->dehydrated(false)->default(false),
            ]),
            DocumentLines::totals(),
        ]);
    }

    /** Copy the lines of the original invoice into a return. */
    public static function fillFromOriginal(?int $id, Set $set): void
    {
        $original = $id ? SalesDocument::query()->with('lines')->find($id) : null;
        if (! $original) {
            return;
        }

        $lines = [];
        foreach ($original->lines as $line) {
            $lines[(string) \Illuminate\Support\Str::uuid()] = $line->only(['item_id', 'unit_id', 'item_batch_id', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'gross', 'discount', 'tax', 'net']);
        }
        $set('lines', $lines);
        $set('customer_id', $original->customer_id);
        $set('account_id', $original->account_id);
        $set('warehouse_id', $original->warehouse_id);
        $set('additional_discount_percent', $original->additional_discount_percent);
        $set('additional_discount', $original->additional_discount);
        foreach (DocumentLines::TOTAL_FIELDS as $f) {
            $set($f, $original->{$f});
        }
    }
}
