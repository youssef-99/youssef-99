<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
