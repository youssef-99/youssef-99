<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ActiveIngredient extends Model
{
    use HasBilingualName;

    protected $table = 'active_ingredients';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('strength');
    }
}
