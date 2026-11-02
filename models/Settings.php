<?php

namespace JosephCrowell\BlogsMd\Models;

use Winter\Storm\Database\Model;
use Winter\Storm\Support\Facades\Config;

/**
 * Backend settings (Settings > Blogs MD). A setting left empty falls back to `config/config.php`
 * (and the `BLOGSMD_*` variables it reads).
 */
class Settings extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $implement = ['System.Behaviors.SettingsModel'];
    public $settingsCode = 'josephcrowell_blogsmd_settings';
    public $settingsFields = 'fields.yaml';

    public $rules = [
        'branch' => ['nullable', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        'posts_path' => ['nullable', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        'categories_path' => ['nullable', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        'author_field' => 'nullable|in:author,committer',
        'on_removed' => 'nullable|in:delete,unpublish',
        'post_url_prefix' => ['nullable', 'regex:/^\/[A-Za-z0-9._\/-]*$/'],
    ];

    /**
     * The backend setting, or the config value when the setting is empty or was never saved.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $value = static::get($key);

        if ($value === null || $value === '') {
            return Config::get('josephcrowell.blogsmd::' . $key, $default);
        }

        return $value;
    }
}
