<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * List-page tabs: All / Posted / Unposted (الكل / المرحلة / الغير مرحلة).
 * Use in a ListRecords page.
 */
trait HasPostedTabs
{
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'posted' => Tab::make(__('Posted'))->modifyQueryUsing(fn (Builder $q) => $q->where('is_posted', true)),
            'unposted' => Tab::make(__('Unposted'))->modifyQueryUsing(fn (Builder $q) => $q->where('is_posted', false)),
        ];
    }
}
