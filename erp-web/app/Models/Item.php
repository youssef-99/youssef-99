<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasBilingualName;

    protected $table = 'items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:4',
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'price_includes_tax' => 'boolean',
            'is_active' => 'boolean',
            'sent_to_epos' => 'boolean',
        ];
    }

    /** ZATCA: an exemption reason only applies to 0% VAT items. */
    protected static function booted(): void
    {
        static::saving(function (Item $item) {
            if ((float) $item->vat_rate > 0) {
                $item->tax_exemption_reason = null;
            }
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ItemBatch::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(ItemSerialNumber::class, 'item_id');
    }

    public function balanceLimits(): HasMany
    {
        return $this->hasMany(ItemBalanceLimit::class, 'item_id');
    }

    public function activeIngredients(): BelongsToMany
    {
        return $this->belongsToMany(ActiveIngredient::class)->withPivot('strength');
    }

    /** Stock on hand (all warehouses unless given). */
    public function balance(?int $warehouseId = null, ?int $branchId = null): float
    {
        return StockService::balance($this->id, $warehouseId, null, $branchId);
    }

    public function isBatchTracked(): bool
    {
        return $this->item_type === 'batch_expiry';
    }

    public function isSerialTracked(): bool
    {
        return $this->item_type === 'serial';
    }

    /** Selling price including VAT. */
    public function getSalePriceInclTaxAttribute(): float
    {
        return round((float) $this->sale_price * (1 + (float) $this->vat_rate / 100), 2);
    }
}
