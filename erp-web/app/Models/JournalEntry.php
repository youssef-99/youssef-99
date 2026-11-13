<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id')->orderBy('sort');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::creating(function (JournalEntry $e) {
            $e->fiscal_year ??= (int) \Illuminate\Support\Carbon::parse($e->date ?? now())->format('Y');
            $e->entry_type ??= 'manual';
            $e->created_by ??= auth()->id();
            if (blank($e->number)) {
                $e->number = \App\Services\DocumentNumber::next('journal.'.$e->entry_type, $e->branch_id, $e->fiscal_year);
            }
        });
    }

    /** Manual vouchers: totals from lines, validate balance, mark posted. */
    public function recalculate(): static
    {
        $this->load('lines');
        $this->forceFill([
            'total_debit' => round((float) $this->lines->sum('debit'), 2),
            'total_credit' => round((float) $this->lines->sum('credit'), 2),
        ])->saveQuietly();

        return $this;
    }
}
