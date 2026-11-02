<?php

namespace JosephCrowell\BlogsMd\Classes;

use Carbon\Carbon;
use Throwable;
use Winter\Storm\Parse\Ini;

/**
 * Parses post and category files: INI settings, a line containing only `;;`, then Markdown.
 *
 * Settings are parsed as data only; nothing from the repository is executed.
 */
class SourceFile
{
    public const SEPARATOR = ';;';

    public const POST_KEYS = ['title', 'slug', 'date', 'excerpt', 'published', 'categories', 'teaser'];
    public const CATEGORY_KEYS = ['name', 'parent'];

    /**
     * First slug segments that would clash with the blog's own routes.
     */
    public const RESERVED_SEGMENTS = ['page', 'category', 'rss.xml'];

    /**
     * @var array Warnings collected while parsing (unknown keys), as messages.
     */
    public array $warnings = [];

    /**
     * Splits a file into its settings and Markdown body.
     *
     * @return array{0: array, 1: string}
     */
    public function split(string $raw, string $path): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));

        foreach ($lines as $index => $line) {
            if (rtrim($line) === static::SEPARATOR) {
                $settings = implode("\n", array_slice($lines, 0, $index));
                $body = implode("\n", array_slice($lines, $index + 1));

                return [$this->parseSettings($settings, $path), $body];
            }
        }

        throw new SourceException(sprintf('%s: no ";;" line separating the settings from the Markdown.', $path));
    }

    /**
     * Parses a post file.
     *
     * @return array{title: string, slug: string, date: ?Carbon, excerpt: ?string, published: bool,
     *     categories: string[], teaser: ?string, body: string}
     */
    public function parsePost(string $raw, string $path, string $postsPath): array
    {
        [$settings, $body] = $this->split($raw, $path);

        $this->warnUnknownKeys($settings, static::POST_KEYS, $path);

        $title = trim((string) ($settings['title'] ?? ''));
        if ($title === '') {
            throw new SourceException(sprintf('%s: the "title" setting is required.', $path));
        }

        if (trim($body) === '') {
            throw new SourceException(sprintf('%s: the post body is empty.', $path));
        }

        $published = filter_var($settings['published'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($published === null) {
            throw new SourceException(sprintf('%s: "published" must be 1 or 0.', $path));
        }

        $categories = [];
        foreach ((array) ($settings['categories'] ?? []) as $category) {
            $categories[] = $this->categorySlug((string) $category, $path);
        }

        $excerpt = isset($settings['excerpt']) ? trim((string) $settings['excerpt']) : null;
        $teaser = isset($settings['teaser']) ? trim((string) $settings['teaser']) : '';

        return [
            'title' => $title,
            'slug' => $this->postSlug($path, $postsPath, $settings['slug'] ?? null),
            'date' => $this->postDate($path, $settings['date'] ?? null),
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'published' => $published,
            'categories' => array_values(array_unique($categories)),
            'teaser' => $teaser === '' ? null : $teaser,
            'body' => $body,
        ];
    }

    /**
     * Parses a category file. The file name (without `.md`) is the slug.
     *
     * @return array{slug: string, name: string, parent: ?string, body: string}
     */
    public function parseCategory(string $raw, string $path): array
    {
        [$settings, $body] = $this->split($raw, $path);

        $this->warnUnknownKeys($settings, static::CATEGORY_KEYS, $path);

        $name = trim((string) ($settings['name'] ?? ''));
        if ($name === '') {
            throw new SourceException(sprintf('%s: the "name" setting is required.', $path));
        }

        $parent = isset($settings['parent']) ? trim((string) $settings['parent']) : '';

        return [
            'slug' => $this->categorySlug(basename($path, '.md'), $path),
            'name' => $name,
            'parent' => $parent === '' ? null : $this->categorySlug($parent, $path),
            'body' => $body,
        ];
    }

    /**
     * Builds the post slug: the lower-cased folder path below the posts folder, then the file slug.
     */
    public function postSlug(string $path, string $postsPath, mixed $override = null): string
    {
        $relative = ltrim(substr($path, strlen(rtrim($postsPath, '/'))), '/');
        $folders = array_filter(explode('/', dirname($relative)), fn ($s) => $s !== '' && $s !== '.');

        $file = trim((string) $override);
        if ($file === '') {
            $file = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', basename($relative, '.md'));
        }

        $segments = array_map('strtolower', array_merge($folders, [$file]));

        foreach ($segments as $segment) {
            if (!preg_match('/^[a-z0-9_\-]+$/', $segment)) {
                throw new SourceException(sprintf(
                    '%s: "%s" is not a valid URL segment (use a-z, 0-9, "-" and "_").',
                    $path,
                    $segment
                ));
            }
        }

        if (in_array($segments[0], static::RESERVED_SEGMENTS, true)) {
            throw new SourceException(sprintf('%s: a post URL can\'t start with "%s".', $path, $segments[0]));
        }

        return implode('/', $segments);
    }

    /**
     * Validates a category slug (3 to 64 characters, a-z, 0-9, "-" and "_").
     */
    public function categorySlug(string $slug, string $path): string
    {
        $slug = strtolower(trim($slug));

        if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
            throw new SourceException(sprintf('%s: "%s" is not a valid category slug.', $path, $slug));
        }

        $length = strlen($slug);
        if ($length < 3 || $length > 64) {
            throw new SourceException(sprintf('%s: category slug "%s" must be 3 to 64 characters long.', $path, $slug));
        }

        return $slug;
    }

    /**
     * The post date: the "date" setting (UTC), else the date in the file name (00:00 UTC), else null.
     */
    public function postDate(string $path, mixed $setting): ?Carbon
    {
        if ($setting !== null && trim((string) $setting) !== '') {
            try {
                return Carbon::parse(trim((string) $setting), 'UTC')->utc();
            } catch (Throwable $e) {
                throw new SourceException(sprintf('%s: "%s" is not a valid date.', $path, $setting));
            }
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', basename($path), $matches)) {
            try {
                return Carbon::createFromFormat('!Y-m-d', $matches[1], 'UTC');
            } catch (Throwable $e) {
                throw new SourceException(sprintf('%s: the file name date "%s" is not valid.', $path, $matches[1]));
            }
        }

        return null;
    }

    protected function parseSettings(string $settings, string $path): array
    {
        if (trim($settings) === '') {
            return [];
        }

        try {
            $parsed = (new Ini)->parse($settings);
        } catch (Throwable $e) {
            throw new SourceException(sprintf('%s: the settings are not valid INI: %s', $path, $e->getMessage()));
        }

        if (!is_array($parsed) || empty($parsed)) {
            throw new SourceException(sprintf('%s: the settings are not valid INI.', $path));
        }

        return $parsed;
    }

    protected function warnUnknownKeys(array $settings, array $known, string $path): void
    {
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $known, true)) {
                $this->warnings[] = sprintf('%s: unknown setting "%s" ignored.', $path, $key);
            }
        }
    }
}
