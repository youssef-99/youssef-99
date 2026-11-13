<?php

namespace App\Filament\Resources\Cash\Shared;

use App\Enums\SalesDocumentType;
use App\Filament\Support\DocumentLines;
use App\Filament\Support\Fields;
use App\Models\SalesDocument;
use App\Services\Calc\DocumentCalculator;
use App\Services\Settings;
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
use Illuminate\Support\Str;

/**
 * Cash sales invoices / returns (فواتير المبيعات النقدية / مرتجعاتها) — posted against the chosen cash account
 * through SalesDocumentService (types cash_sales_invoice / cash_sales_return).
 */
class CashSalesDocumentForm
{
    public static function configure(Schema $schema, SalesDocumentType $type, bool $headerOnly = false): Schema
    {
        $header = static::header($type);

        if ($headerOnly) {
            return $schema->columns(['default' => 1, 'md' => 2])->components($header);
        }

        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3, 'xl' => 4])->schema($header),
            DocumentLines::scanner(),
            DocumentLines::salesRepeater(),
            Section::make()->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(2),
                Toggle::make('post_now')->label($type->isReturn() ? 'Post Return?' : 'Post Invoice?')->dehydrated(false)->default(false)->hiddenOn('view'),
            ]),
            DocumentLines::totals(),
        ]);
    }

    /** @return array<int, mixed> */
    public static function header(SalesDocumentType $type): array
    {
        $isReturn = $type->isReturn();

        $fields = [
            Fields::branch(),
            Fields::number(label: $isReturn ? 'Return No.' : 'Invoice No.'),
            Fields::date(label: $isReturn ? 'Return Date' : 'Invoice Date'),
            Fields::account(label: 'Account', modifyQuery: fn (Builder $query) => $query->whereIn('type', ['cash', 'bank']))
                ->default(fn () => Settings::account('cash'))
                ->required(),
            Fields::relation('cash_customer_id', 'cashCustomer', 'Cash Customer'),
            TextInput::make('manual_no')->label('Manual Invoice No.')->maxLength(50),
            Hidden::make('payment_method')->default('cash'),
        ];

        if ($isReturn) {
            $fields[] = Select::make('original_document_id')
                ->label('Original Sales Invoice')
                ->relationship('originalDocument', 'number', fn (Builder $query) => $query
                    ->where('type', SalesDocumentType::CashSalesInvoice->value)
                    ->where('is_posted', true))
                ->getOptionLabelFromRecordUsing(fn (SalesDocument $r) => $r->number.' - '.$r->date->format('d/m/Y').' ('.number_format((float) $r->grand_total, 2).')')
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set) => static::fillFromOriginal($state ? (int) $state : null, $get, $set));
        }

        $fields[] = Textarea::make('notes')->label('Notes')->rows(2)->columnSpanFull()->visibleOn('create');
        $fields[] = TextInput::make('voucher_no')->label('Voucher No.')
            ->formatStateUsing(fn (?SalesDocument $record) => $record?->journalEntry?->number)
            ->dehydrated(false)->disabled()->visibleOn('view');
        $fields[] = TextInput::make('zatca_status')->label('Submission Status')
            ->formatStateUsing(fn (?string $state) => $state ? __(Str::headline($state)) : null)
            ->dehydrated(false)->disabled()->visibleOn('view');

        return $fields;
    }

    /** Copy the original cash invoice lines into the return. */
    public static function fillFromOriginal(?int $id, Get $get, Set $set): void
    {
        $original = $id ? SalesDocument::query()->with('lines')->find($id) : null;
        if (! $original) {
            return;
        }

        $lines = [];
        foreach ($original->lines as $line) {
            $row = $line->only(['item_id', 'unit_id', 'item_batch_id', 'quantity', 'unit_price', 'discount_percent', 'tax_percent']);
            $lines[(string) Str::uuid()] = array_merge($row, array_intersect_key(DocumentCalculator::line($row), array_flip(['gross', 'discount', 'tax', 'net'])));
        }

        $set('lines', $lines);
        $set('cash_customer_id', $original->cash_customer_id);
        $set('account_id', $original->account_id);
        $set('additional_discount', (float) $original->additional_discount);
        $set('additional_discount_percent', (float) $original->additional_discount_percent);
        DocumentLines::updateTotals($get, $set);
    }
}
