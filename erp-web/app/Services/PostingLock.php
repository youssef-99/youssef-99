<?php

namespace App\Services;

use App\Exceptions\ErpException;
use Illuminate\Database\Eloquent\Model;

/**
 * Row locking for post / unpost: inside the posting transaction the record is re-read with
 * SELECT ... FOR UPDATE and its posted flag re-checked, so two concurrent (or repeated) requests
 * holding a stale copy of the same document cannot both post or both unpost it.
 */
class PostingLock
{
    /**
     * Lock the record's row and assert its current posted state. Must run inside DB::transaction().
     *
     * @param  bool  $expectPosted  false before posting, true before unposting
     */
    public static function lock(Model $record, bool $expectPosted, string $column = 'is_posted'): void
    {
        $fresh = $record->newQuery()->lockForUpdate()->find($record->getKey())
            ?? throw new ErpException(__('The document was not found.'));

        if ((bool) $fresh->getAttribute($column) !== $expectPosted) {
            $record->setAttribute($column, $fresh->getAttribute($column));
            $record->syncOriginalAttribute($column);

            throw new ErpException($expectPosted
                ? __('This document is not posted.')
                : __('This document is already posted.'));
        }
    }
}
