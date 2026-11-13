<?php

namespace App\Models;

use App\Services\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Murabaha financing contract (عقد تمويل مرابحة).
 * `bank_profit_margin` is a percentage of the murabaha amount.
 */
class FinancingContract extends Model
{
    protected $table = 'financing_contracts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contract_date' => 'date',
            'first_installment_date' => 'date',
            'murabaha_amount' => 'decimal:2',
            'bank_profit_margin' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id');
    }

    public function loanAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loan_account_id');
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'funding_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FinancingInstallment::class, 'financing_contract_id')->orderBy('sequence');
    }

    protected static function booted(): void
    {
        static::creating(function (FinancingContract $contract): void {
            if (blank($contract->number)) {
                $contract->number = DocumentNumber::nextCode(static::class, 'number');
            }
        });
    }
}
