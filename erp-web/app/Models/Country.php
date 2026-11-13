<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasBilingualName;

    protected $table = 'countries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'country_id');
    }
}
