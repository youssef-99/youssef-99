<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasBilingualName;

    protected $table = 'suppliers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'register_expiry_date' => 'date',
            'opening_debit' => 'decimal:2',
            'opening_credit' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function supplierGroup(): BelongsTo
    {
        return $this->belongsTo(SupplierGroup::class, 'supplier_group_id');
    }

    public function purchaseDocuments(): HasMany
    {
        return $this->hasMany(PurchaseDocument::class, 'supplier_id');
    }
}
