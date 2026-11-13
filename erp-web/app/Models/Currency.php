<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasBilingualName;

    protected $table = 'currencies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Only one currency can be the default (local) currency.
     */
    protected static function booted(): void
    {
        static::saved(function (Currency $currency): void {
            if ($currency->is_default) {
                static::query()->whereKeyNot($currency->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }
}
