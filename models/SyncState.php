<?php

namespace JosephCrowell\BlogsMd\Models;

use Winter\Storm\Database\Model;

/**
 * The single row recording the last repository commit that was synced.
 */
class SyncState extends Model
{
    public $table = 'josephcrowell_blogsmd_sync_state';

    protected $dates = ['processed_at'];

    public static function current(): static
    {
        return static::query()->orderBy('id')->first() ?? new static;
    }
}
