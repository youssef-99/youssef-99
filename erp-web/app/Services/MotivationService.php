<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Models\Motivation;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Staff incentives (الحوافز): an amount per unit sold or a percent of the sales value (before VAT),
 * per item or item category, for the user who created the posted sales document. Returns deduct.
 */
class MotivationService
{
    public const TYPES = [
        'per_unit' => 'Amount per Unit',
        'percent' => 'Percent of Sales',
    ];

    /**
     * Incentive earned by one line, or 0 when no incentive applies.
     *
     * @param  Collection<int, Motivation>  $motivations
     */
    public static function forLine(SalesDocumentLine $line, SalesDocument $doc, Collection $motivations): float
    {
        $motivation = static::match($line, $doc, $motivations);
        if (! $motivation) {
            return 0.0;
        }

        $quantity = (float) $line->quantity;
        $value = (float) $motivation->value;
        $amount = $motivation->motivation_type === 'percent'
            ? ((float) $line->gross - (float) $line->discount) * $value / 100
            : $quantity * $value;

        return round(SalesDocumentType::tryFrom($doc->type)?->isReturn() ? -$amount : $amount, 2);
    }

    /**
     * Earned incentives per selling user in a period.
     *
     * @return Collection<int, array{user_id: ?int, user_name: string, quantity: float, sales: float, incentive: float}>
     */
    public static function earned(CarbonInterface|string $from, CarbonInterface|string $to, ?int $branchId = null, ?int $userId = null): Collection
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $motivations = Motivation::query()
            ->where('is_active', true)
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->get();

        if ($motivations->isEmpty()) {
            return collect();
        }

        $types = collect(SalesDocumentType::cases())->filter(fn (SalesDocumentType $t) => $t->isPostable())->map->value->all();

        $documents = SalesDocument::query()
            ->with('lines.item')
            ->whereIn('type', $types)
            ->where('is_posted', true)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($userId, fn ($q, $u) => $q->where('created_by', $u))
            ->get();

        $rows = [];
        foreach ($documents as $doc) {
            $sign = SalesDocumentType::tryFrom($doc->type)?->isReturn() ? -1 : 1;
            foreach ($doc->lines as $line) {
                $incentive = static::forLine($line, $doc, $motivations);
                if ($incentive == 0.0) {
                    continue;
                }
                $key = (int) $doc->created_by;
                $rows[$key] ??= ['user_id' => $doc->created_by, 'user_name' => '', 'quantity' => 0.0, 'sales' => 0.0, 'incentive' => 0.0];
                $rows[$key]['quantity'] += $sign * (float) $line->quantity;
                $rows[$key]['sales'] += $sign * ((float) $line->gross - (float) $line->discount);
                $rows[$key]['incentive'] += $incentive;
            }
        }

        $users = User::query()->whereIn('id', array_filter(array_keys($rows)))->get()->keyBy('id');

        return collect($rows)->map(fn (array $row) => array_merge($row, [
            'user_name' => (string) ($users[$row['user_id']]?->name ?? $users[$row['user_id']]?->username ?? '-'),
            'quantity' => round($row['quantity'], 3),
            'sales' => round($row['sales'], 2),
            'incentive' => round($row['incentive'], 2),
        ]))->sortByDesc('incentive')->values();
    }

    /** @param  Collection<int, Motivation>  $motivations */
    protected static function match(SalesDocumentLine $line, SalesDocument $doc, Collection $motivations): ?Motivation
    {
        $date = Carbon::parse($doc->date)->toDateString();
        $categoryId = $line->item?->category_id;

        return $motivations
            ->filter(fn (Motivation $m) => $m->from_date->toDateString() <= $date && $m->to_date->toDateString() >= $date)
            ->filter(fn (Motivation $m) => ! $m->branch_id || (int) $m->branch_id === (int) $doc->branch_id)
            ->filter(fn (Motivation $m) => ! $m->user_id || (int) $m->user_id === (int) $doc->created_by)
            ->filter(fn (Motivation $m) => ((int) $m->item_id === (int) $line->item_id) || (! $m->item_id && $m->category_id && (int) $m->category_id === (int) $categoryId))
            ->sortBy(fn (Motivation $m) => [$m->item_id ? 0 : 1, $m->user_id ? 0 : 1, $m->branch_id ? 0 : 1])
            ->first();
    }
}
