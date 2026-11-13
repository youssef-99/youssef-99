<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use HasBilingualName;

    protected $table = 'customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'discount_eligible' => 'boolean',
            'register_expiry_date' => 'date',
            'account_opening_date' => 'date',
            'last_reconciliation_date' => 'date',
            'opening_debit' => 'decimal:2',
            'opening_credit' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'attachments' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }

    public function salesPolicy(): BelongsTo
    {
        return $this->belongsTo(SalesPolicy::class, 'sales_policy_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'salesman_id');
    }

    public function marketingRep(): BelongsTo
    {
        return $this->belongsTo(MarketingRep::class, 'marketing_rep_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'customer_id');
    }
}
