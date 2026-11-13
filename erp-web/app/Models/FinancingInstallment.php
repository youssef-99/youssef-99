<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancingInstallment extends Model
{
    protected $table = 'financing_installments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'is_paid' => 'boolean',
            'paid_at' => 'date',
        ];
    }

    public function financingContract(): BelongsTo
    {
        return $this->belongsTo(FinancingContract::class, 'financing_contract_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
