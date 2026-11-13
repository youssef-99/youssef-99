<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingRep extends Model
{
    use HasBilingualName;

    protected $table = 'marketing_reps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
