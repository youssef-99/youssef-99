<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeCollection extends Model
{
    protected $table = 'cheque_collections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'from_date' => 'date',
            'to_date' => 'date',
            'total' => 'decimal:2',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(VoucherLine::class, 'cheque_collection_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ChequeCollection $c) {
            $c->fiscal_year ??= (int) \Illuminate\Support\Carbon::parse($c->date ?? now())->format('Y');
            if (blank($c->number)) {
                $c->number = \App\Services\DocumentNumber::next('cheque_collection', $c->branch_id, $c->fiscal_year);
            }
        });
    }
}
