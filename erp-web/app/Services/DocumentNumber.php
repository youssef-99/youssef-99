<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Sequential document numbers per (key, branch, fiscal year).
 */
class DocumentNumber
{
    /** Peek at the next number without reserving it (for form defaults). */
    public static function peek(string $key, ?int $branchId = null, ?int $year = null): string
    {
        $last = DocumentSequence::query()
            ->where(['key' => $key, 'branch_id' => $branchId ?? 0, 'fiscal_year' => $year ?? 0])
            ->value('last_number') ?? 0;

        return (string) ($last + 1);
    }

    /** Reserve and return the next number. */
    public static function next(string $key, ?int $branchId = null, ?int $year = null): string
    {
        return DB::transaction(function () use ($key, $branchId, $year) {
            $seq = DocumentSequence::query()
                ->where(['key' => $key, 'branch_id' => $branchId ?? 0, 'fiscal_year' => $year ?? 0])
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = DocumentSequence::query()->create(['key' => $key, 'branch_id' => $branchId ?? 0, 'fiscal_year' => $year ?? 0, 'last_number' => 0]);
            }

            $seq->increment('last_number');

            return (string) $seq->last_number;
        });
    }

    /** Keep the sequence ahead of a manually typed number. */
    public static function bump(string $key, ?int $branchId, ?int $year, string $number): void
    {
        if (! ctype_digit($number)) {
            return;
        }

        $seq = DocumentSequence::query()->firstOrCreate(
            ['key' => $key, 'branch_id' => $branchId ?? 0, 'fiscal_year' => $year ?? 0],
            ['last_number' => 0],
        );

        if ((int) $number > $seq->last_number) {
            $seq->update(['last_number' => (int) $number]);
        }
    }

    /** Next code for master data, e.g. customers: numeric max(code)+1. */
    public static function nextCode(string $modelClass, string $column = 'code', int $start = 1): string
    {
        $max = $modelClass::query()->pluck($column)->filter(fn ($c) => ctype_digit((string) $c))->map(fn ($c) => (int) $c)->max();

        return (string) (($max ?? ($start - 1)) + 1);
    }
}
