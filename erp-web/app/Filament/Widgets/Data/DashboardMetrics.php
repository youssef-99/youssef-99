<?php

namespace App\Filament\Widgets\Data;

use App\Enums\SalesDocumentType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read-only sales aggregates for the dashboard (لوحة التحكم).
 *
 * Counts posted sales, POS and cash sales invoices minus their returns, between two dates (inclusive).
 * "With tax" uses amounts including VAT (grand total / line net); "without tax" excludes VAT.
 */
class DashboardMetrics
{
    /** @var array<int, string> */
    public const INVOICE_TYPES = [
        SalesDocumentType::SalesInvoice->value,
        SalesDocumentType::PosInvoice->value,
        SalesDocumentType::CashSalesInvoice->value,
    ];

    /** @var array<int, string> */
    public const RETURN_TYPES = [
        SalesDocumentType::SalesReturn->value,
        SalesDocumentType::PosReturn->value,
        SalesDocumentType::CashSalesReturn->value,
    ];

    public const DEFAULT_DAYS = 7;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $withTax = true,
    ) {
        if ($this->from->greaterThan($this->to)) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }
    }

    /**
     * @param  array{from?: ?string, to?: ?string, tax?: ?string}|null  $filters  dashboard page filters
     */
    public static function fromFilters(?array $filters): self
    {
        $to = static::parseDate($filters['to'] ?? null) ?? CarbonImmutable::today();
        $from = static::parseDate($filters['from'] ?? null) ?? $to->subDays(static::DEFAULT_DAYS - 1);

        return new self($from->startOfDay(), $to->startOfDay(), ($filters['tax'] ?? 'with') !== 'without');
    }

    protected static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{sales: float, invoices_total: float, invoices_count: int, returns_total: float, returns_count: int, vat: float}
     */
    public function summary(): array
    {
        $rows = $this->documents()
            ->selectRaw('type, COUNT(*) as documents_count, SUM(grand_total) as grand_total, SUM(net_total) as net_total, SUM(tax_total) as tax_total')
            ->groupBy('type')
            ->get();

        $invoices = $rows->whereIn('type', self::INVOICE_TYPES);
        $returns = $rows->whereIn('type', self::RETURN_TYPES);
        $amountColumn = $this->withTax ? 'grand_total' : 'net_total';

        $invoicesTotal = round((float) $invoices->sum($amountColumn), 2);
        $returnsTotal = round((float) $returns->sum($amountColumn), 2);

        return [
            'sales' => round($invoicesTotal - $returnsTotal, 2),
            'invoices_total' => $invoicesTotal,
            'invoices_count' => (int) $invoices->sum('documents_count'),
            'returns_total' => $returnsTotal,
            'returns_count' => (int) $returns->sum('documents_count'),
            'vat' => round((float) $invoices->sum('tax_total') - (float) $returns->sum('tax_total'), 2),
        ];
    }

    /**
     * Top items by net quantity sold (sales minus returns).
     *
     * @return Collection<int, array{item_id: int, name: string, code: string, quantity: float, amount: float}>
     */
    public function topItems(int $limit = 10): Collection
    {
        $returnTypes = self::RETURN_TYPES;
        $placeholders = implode(', ', array_fill(0, count($returnTypes), '?'));
        $amountExpression = $this->withTax ? 'l.net' : '(l.net - l.tax)';

        $rows = SalesDocumentLine::query()
            ->from('sales_document_lines as l')
            ->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')
            ->where('d.is_posted', true)
            ->whereIn('d.type', [...self::INVOICE_TYPES, ...self::RETURN_TYPES])
            ->whereDate('d.date', '>=', $this->from->toDateString())
            ->whereDate('d.date', '<=', $this->to->toDateString())
            ->groupBy('l.item_id')
            ->selectRaw("l.item_id, SUM(CASE WHEN d.type IN ({$placeholders}) THEN -l.quantity ELSE l.quantity END) as net_quantity", $returnTypes)
            ->selectRaw("SUM(CASE WHEN d.type IN ({$placeholders}) THEN -{$amountExpression} ELSE {$amountExpression} END) as net_amount", $returnTypes)
            ->toBase()
            ->get()
            ->filter(fn (object $row): bool => (float) $row->net_quantity > 0)
            ->sortByDesc(fn (object $row): float => (float) $row->net_quantity)
            ->take($limit)
            ->values();

        $items = Item::query()->whereIn('id', $rows->pluck('item_id'))->get()->keyBy('id');

        return $rows->map(fn (object $row): array => [
            'item_id' => (int) $row->item_id,
            'name' => (string) ($items->get($row->item_id)?->name ?? '#'.$row->item_id),
            'code' => (string) ($items->get($row->item_id)?->code ?? ''),
            'quantity' => round((float) $row->net_quantity, 3),
            'amount' => round((float) $row->net_amount, 2),
        ]);
    }

    /**
     * Net sales per branch per day (or per month for periods over 62 days).
     *
     * @return array{labels: array<int, string>, branches: array<int, array{branch_id: int, name: string, total: float, values: array<int, float>}>}
     */
    public function salesByBranch(): array
    {
        $byMonth = $this->from->diffInDays($this->to) > 62;
        $format = $byMonth ? 'Y-m' : 'Y-m-d';
        $buckets = [];

        foreach (CarbonPeriod::create($this->from, $byMonth ? '1 month' : '1 day', $this->to) as $date) {
            $buckets[$date->format($format)] = 0.0;
        }

        if ($byMonth) {
            $buckets[$this->to->format($format)] = 0.0;
        }

        $amountColumn = $this->withTax ? 'grand_total' : 'net_total';
        $rows = $this->documents()
            ->selectRaw("branch_id, date, type, SUM({$amountColumn}) as amount")
            ->groupBy('branch_id', 'date', 'type')
            ->toBase()
            ->get();

        $branchIds = $rows->pluck('branch_id')->unique()->values();
        $branches = Branch::query()->whereIn('id', $branchIds)->orderBy('code')->get();
        $series = [];

        foreach ($branches as $branch) {
            $series[$branch->id] = ['branch_id' => $branch->id, 'name' => $branch->name, 'total' => 0.0, 'values' => $buckets];
        }

        foreach ($rows as $row) {
            if (! isset($series[$row->branch_id])) {
                continue;
            }

            $key = CarbonImmutable::parse($row->date)->format($format);
            $sign = in_array($row->type, self::RETURN_TYPES, true) ? -1 : 1;

            if (array_key_exists($key, $series[$row->branch_id]['values'])) {
                $series[$row->branch_id]['values'][$key] += $sign * (float) $row->amount;
            }

            $series[$row->branch_id]['total'] += $sign * (float) $row->amount;
        }

        return [
            'labels' => array_keys($buckets),
            'branches' => array_values(array_map(fn (array $branch): array => [
                ...$branch,
                'total' => round($branch['total'], 2),
                'values' => array_map(fn (float $value): float => round($value, 2), array_values($branch['values'])),
            ], $series)),
        ];
    }

    /** @return Builder<SalesDocument> */
    protected function documents(): Builder
    {
        return SalesDocument::query()
            ->where('is_posted', true)
            ->whereIn('type', [...self::INVOICE_TYPES, ...self::RETURN_TYPES])
            ->whereDate('date', '>=', $this->from->toDateString())
            ->whereDate('date', '<=', $this->to->toDateString());
    }
}
