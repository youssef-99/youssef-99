<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Records with name_ar / name_en. `name` returns the name in the current locale,
 * `label` returns "code - name" when a code column exists.
 */
trait HasBilingualName
{
    public function getNameAttribute(): string
    {
        $en = $this->attributes['name_en'] ?? null;
        $ar = $this->attributes['name_ar'] ?? '';

        return app()->getLocale() === 'en' && filled($en) ? $en : (string) ($ar ?: $en);
    }

    public function getLabelAttribute(): string
    {
        $code = $this->attributes['code'] ?? $this->attributes['number'] ?? null;

        return filled($code) ? "{$code} - {$this->name}" : $this->name;
    }

    public function scopeSearchName(Builder $query, ?string $search): Builder
    {
        if (blank($search)) {
            return $query;
        }

        $columns = array_intersect(['code', 'number', 'name_ar', 'name_en', 'barcode', 'gtin'], $this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable()));

        return $query->where(function (Builder $q) use ($columns, $search) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$search}%");
            }
        });
    }
}
