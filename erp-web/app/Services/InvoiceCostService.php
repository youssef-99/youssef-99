<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invoice items cost (تكلفة أصناف الفواتير): re-costs posted sales lines from the moving-average
 * ledger and regenerates the COGS / inventory part of their journal entries.
 */
class InvoiceCostService
{
    /** Posted sales lines (invoices, POS, cash sales and their returns) for the cost page. */
    public static function linesQuery(): Builder
    {
        return SalesDocumentLine::query()
            ->whereHas('salesDocument', fn (Builder $q) => $q
                ->where('is_posted', true)
                ->whereIn('type', static::postableTypes()));
    }

    /** @return list<string> */
    public static function postableTypes(): array
    {
        return collect(SalesDocumentType::cases())
            ->filter(fn (SalesDocumentType $type) => $type->isPostable())
            ->map(fn (SalesDocumentType $type) => $type->value)
            ->values()
            ->all();
    }

    /**
     * Update Cost (تحديث التكلفة): replay each item's ledger and re-cost the sales movements and
     * lines dated within the range. Returns use the (re-costed) cost of their original invoice line.
     *
     * @return array{items: int, lines: int, documents: int}
     */
    public static function updateCosts(Carbon|string $from, Carbon|string $to, ?int $branchId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $documents = static::documentsQuery($from, $to, $branchId)->get(['id', 'type', 'original_document_id']);

        if ($documents->isEmpty()) {
            return ['items' => 0, 'lines' => 0, 'documents' => 0];
        }

        $documentsById = $documents->keyBy('id');
        $morph = (new SalesDocument)->getMorphClass();
        $itemIds = StockMovement::query()
            ->where('source_type', $morph)
            ->whereIn('source_id', $documentsById->keys())
            ->distinct()
            ->pluck('item_id');

        $lineCount = 0;

        DB::transaction(function () use ($itemIds, $documentsById, $morph, &$lineCount) {
            foreach ($itemIds as $itemId) {
                /** @var array<int, float> $newCosts sales_document_id => unit cost */
                $newCosts = [];

                $average = StockService::replayAverageCost((int) $itemId, function (StockMovement $movement, float $average) use ($documentsById, $morph, $itemId, &$newCosts): ?float {
                    if ($movement->source_type !== $morph || ! $documentsById->has($movement->source_id)) {
                        return null;
                    }

                    $document = $documentsById->get($movement->source_id);
                    $type = SalesDocumentType::from($document->type);

                    if ($type->isReturn()) {
                        $originalCost = $document->original_document_id
                            ? ($newCosts[$document->original_document_id] ?? static::lineCost($document->original_document_id, (int) $itemId))
                            : null;
                        $cost = $originalCost ?: $average;
                    } else {
                        $cost = $average;
                    }

                    if ($cost <= 0) {
                        return null;
                    }

                    $newCosts[$document->id] = $cost;

                    return $cost;
                });

                foreach ($newCosts as $documentId => $cost) {
                    $lineCount += SalesDocumentLine::query()
                        ->where('sales_document_id', $documentId)
                        ->where('item_id', $itemId)
                        ->update(['unit_cost' => round($cost, 4)]);
                }

                Item::query()->whereKey($itemId)->update(['average_cost' => $average]);
            }

            foreach ($documentsById->keys() as $documentId) {
                $costTotal = (float) SalesDocumentLine::query()
                    ->where('sales_document_id', $documentId)
                    ->sum(DB::raw('(quantity + free_qty) * unit_cost'));
                SalesDocument::query()->whereKey($documentId)->update(['cost_total' => round($costTotal, 2)]);
            }
        });

        return ['items' => $itemIds->count(), 'lines' => $lineCount, 'documents' => $documents->count()];
    }

    /**
     * Post Invoice Entries (ترحيل قيود الفواتير): rebuild the COGS / inventory lines of each posted
     * document's journal entry from its current cost_total, keeping every other line as is.
     *
     * @return int number of entries regenerated
     */
    public static function postEntries(Carbon|string $from, Carbon|string $to, ?int $branchId = null): int
    {
        $cogs = Settings::account('cogs');
        $inventory = Settings::account('inventory');
        if (! $cogs || ! $inventory) {
            return 0;
        }

        $count = 0;
        $documents = static::documentsQuery(Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString(), $branchId)
            ->whereNotNull('journal_entry_id')
            ->get();

        DB::transaction(function () use ($documents, $cogs, $inventory, &$count) {
            foreach ($documents as $document) {
                /** @var JournalEntry|null $entry */
                $entry = JournalEntry::query()->with('lines')->find($document->journal_entry_id);
                if (! $entry) {
                    continue;
                }

                $type = SalesDocumentType::from($document->type);
                $sign = $type->isReturn() ? -1 : 1;
                $description = $type->label().' #'.$document->number;

                $lines = $entry->lines
                    ->reject(fn ($line) => in_array((int) $line->account_id, [$cogs, $inventory], true))
                    ->map(fn ($line) => $line->only(['account_id', 'debit', 'credit', 'description', 'salesman_id', 'cost_center_id', 'accreditation_id', 'document_no', 'document_date']))
                    ->values()
                    ->all();

                $cost = round((float) $document->cost_total, 2);
                if ($cost > 0) {
                    $lines[] = SalesDocumentService::side($cogs, $sign * $cost, $description);
                    $lines[] = SalesDocumentService::side($inventory, -$sign * $cost, $description);
                }

                JournalService::record($document, $entry->entry_type, $document->branch_id, $document->date, $lines, $entry->description);
                $count++;
            }
        });

        return $count;
    }

    protected static function documentsQuery(string $from, string $to, ?int $branchId): Builder
    {
        return SalesDocument::query()
            ->where('is_posted', true)
            ->whereIn('type', static::postableTypes())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));
    }

    protected static function lineCost(int $documentId, int $itemId): ?float
    {
        $cost = SalesDocumentLine::query()->where('sales_document_id', $documentId)->where('item_id', $itemId)->value('unit_cost');

        return $cost !== null ? (float) $cost : null;
    }

    /** @return Collection<int, string> */
    public static function typeOptions(): Collection
    {
        return collect(SalesDocumentType::cases())
            ->filter(fn (SalesDocumentType $type) => $type->isPostable())
            ->mapWithKeys(fn (SalesDocumentType $type) => [$type->value => $type->label()]);
    }
}
