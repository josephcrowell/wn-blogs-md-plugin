<?php

namespace JosephCrowell\BlogsMd\Models;

use Winter\Storm\Database\Model;

/**
 * Maps a Markdown file in the blog repository to the Winter.Blog record it produces.
 */
class Source extends Model
{
    public const TYPE_POST = 'post';
    public const TYPE_CATEGORY = 'category';

    public $table = 'josephcrowell_blogsmd_sources';

    protected $fillable = [
        'type',
        'model_id',
        'source_path',
        'content_hash',
        'removed_at',
        'author_name',
        'author_email',
        'author_github',
        'author_date',
    ];

    protected $dates = ['removed_at', 'author_date'];
}
