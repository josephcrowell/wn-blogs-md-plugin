<?php

namespace JosephCrowell\BlogsMd\Classes;

use Backend\Models\User as BackendUser;
use Carbon\Carbon;
use finfo;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use JosephCrowell\BlogsMd\Models\Settings;
use JosephCrowell\BlogsMd\Models\Source;
use System\Classes\PluginManager;
use System\Models\File;
use Throwable;
use Winter\Blog\Models\Category;
use Winter\Blog\Models\Post;
use Winter\Storm\Support\Facades\Event;
use Winter\Storm\Support\Facades\Html;
use Winter\Storm\Support\Facades\Markdown;
use Winter\Storm\Support\Str;

/**
 * Applies the blog repository at one commit to Winter.Blog's categories and posts.
 */
class Synchroniser
{
    /**
     * Markdown images: `![alt](url)`, `![alt](<url>)` and `![alt](url "title")`.
     */
    protected const IMAGE_PATTERN = '/(!\[[^\]]*\]\(\s*)(<[^>\n]+>|[^)\s]+)((?:\s+(?:"[^"]*"|\'[^\']*\'))?\s*\))/';

    /**
     * @var array Paths whose post was renamed in this run; never skipped as unchanged.
     */
    protected array $renamedPaths = [];

    /**
     * @param array $files Every blob in the tree at `$sha`, as `path => blob sha`.
     */
    public function __construct(
        protected GitRepository $git,
        protected string $sha,
        protected array $files,
        protected SyncResult $result,
        protected bool $force = false
    ) {
    }

    /**
     * Creates or updates categories from `categories/<slug>.md` files.
     */
    public function syncCategories(): void
    {
        $parents = [];

        foreach ($this->categoryFiles() as $path => $blob) {
            try {
                $source = Source::where('source_path', $path)->first();

                if ($source && $source->content_hash === $blob && !$this->force) {
                    continue;
                }

                $parser = new SourceFile;
                $data = $parser->parseCategory($this->git->read($this->sha, $path), $path);
                $this->logWarnings($parser);

                if ($this->referencesRelativeImage($data['body'])) {
                    Log::warning(sprintf('Blogs MD: %s: category descriptions can\'t contain images; ignored.', $path));
                }

                $category = Category::where('slug', $data['slug'])->first() ?? new Category;
                $category->name = $data['name'];
                $category->slug = $data['slug'];
                $category->description = trim($data['body']) === ''
                    ? null
                    : Html::clean(Markdown::parse(trim($data['body'])));
                $category->save();

                Source::updateOrCreate(['source_path' => $path], [
                    'type' => Source::TYPE_CATEGORY,
                    'model_id' => $category->id,
                    'content_hash' => $blob,
                    'removed_at' => null,
                ]);

                $parents[$path] = [$category, $data['parent']];
                $this->result->categories++;
            } catch (Throwable $e) {
                $this->fail($path, $e);
            }
        }

        // Parents are resolved after every file is saved, so a parent may be defined in any file.
        foreach ($parents as $path => [$category, $parentSlug]) {
            try {
                $parentId = null;

                if ($parentSlug !== null) {
                    $parent = Category::where('slug', $parentSlug)->first();

                    if (!$parent || $parent->id === $category->id) {
                        Log::error(sprintf(
                            'Blogs MD: %s: parent category "%s" doesn\'t exist; kept at the top level.',
                            $path,
                            $parentSlug
                        ));
                    } else {
                        $parentId = $parent->id;
                    }
                }

                if ((int) $category->parent_id !== (int) $parentId) {
                    $category->parent_id = $parentId;
                    $category->save();
                }
            } catch (Throwable $e) {
                $this->fail($path, $e);
            }
        }
    }

    /**
     * Creates, updates and removes posts from the Markdown files below the posts folder.
     *
     * @param array $renames Files renamed since the last synced commit, as `old path => new path`.
     */
    public function syncPosts(array $renames): void
    {
        foreach ($renames as $old => $new) {
            $source = Source::where('type', Source::TYPE_POST)->where('source_path', $old)->first();

            if ($source && !Source::where('source_path', $new)->exists()) {
                $source->source_path = $new;
                $source->save();
                $this->renamedPaths[$new] = true;
            }
        }

        $postFiles = $this->postFiles();

        foreach ($postFiles as $path => $blob) {
            try {
                $this->syncPost($path, $blob);
            } catch (Throwable $e) {
                $this->fail($path, $e);
            }
        }

        $this->removePosts(array_keys($postFiles));
    }

    /**
     * Handles category files that have gone. A category still used by any post, or with child categories,
     * is kept under a generated name; otherwise it is deleted.
     */
    public function removeCategories(): void
    {
        $present = array_keys($this->categoryFiles());

        $removed = Source::where('type', Source::TYPE_CATEGORY)
            ->whereNotIn('source_path', $present ?: [''])
            ->get();

        foreach ($removed as $source) {
            try {
                $category = Category::find($source->model_id);

                if ($category) {
                    $inUse = DB::table('winter_blog_posts_categories')->where('category_id', $category->id)->exists()
                        || Category::where('parent_id', $category->id)->exists();

                    if ($inUse) {
                        $category->name = $this->generatedCategoryName($category->slug);
                        $category->save();
                    } else {
                        $category->delete();
                    }
                }

                $source->delete();
            } catch (Throwable $e) {
                $this->fail($source->source_path, $e);
            }
        }
    }

    protected function syncPost(string $path, string $blob): void
    {
        $postsPath = (string) $this->config('posts_path', 'posts');
        $parser = new SourceFile;
        $data = $parser->parsePost($this->git->read($this->sha, $path), $path, $postsPath);
        $this->logWarnings($parser);

        $images = $this->findImages($data['body'], $path);
        $imageBlobs = [];
        foreach ($images as $image) {
            if ($image['path'] !== null && isset($this->files[$image['path']])) {
                $imageBlobs[] = $this->files[$image['path']];
            }
        }
        $imageBlobs = array_unique($imageBlobs);
        sort($imageBlobs);

        $teaserPath = $this->teaserPath($data['teaser'], $path);

        // A teaser is only added to the hash when set, so posts without one keep their existing hash.
        $hash = sha1(
            $blob . implode(',', $imageBlobs)
            . ($teaserPath !== null ? '|teaser:' . $teaserPath . ':' . $this->files[$teaserPath] : '')
        );

        $source = Source::where('type', Source::TYPE_POST)->where('source_path', $path)->first();

        if (
            $source
            && $source->content_hash === $hash
            && is_null($source->removed_at)
            && !$this->force
            && !isset($this->renamedPaths[$path])
        ) {
            $this->result->unchanged++;
            return;
        }

        $post = $source ? Post::find($source->model_id) : null;
        $isNew = is_null($post);
        $post = $post ?? new Post;
        $oldSlug = $isNew ? null : $post->slug;

        $author = $this->author($source, $path);
        $githubLogin = $this->githubLogin($author['email']);
        $authorName = $author['name'];

        $publishedAt = $data['date'] ?? $author['date'];
        if ($data['published'] && is_null($publishedAt)) {
            $publishedAt = Carbon::now('UTC');
        }

        $sessionKey = uniqid('blogsmd', true);
        $newFileIds = [];
        $keptFileIds = [];
        $content = $this->attachImages($post, $isNew, $data['body'], $images, $sessionKey, $newFileIds, $keptFileIds);

        if ($teaserPath !== null) {
            $existingTeasers = $isNew ? collect() : $post->featured_images()->get();
            $this->storeImage(
                $post,
                'featured_images',
                $existingTeasers,
                $teaserPath,
                $sessionKey,
                $newFileIds,
                $keptFileIds
            );
        }

        $post->title = $data['title'];
        $post->slug = $data['slug'];
        $post->excerpt = $data['excerpt'];
        $post->content = $content;
        $post->published = $data['published'];
        $post->published_at = $publishedAt;
        $post->metadata = [
            'source_path' => $path,
            'source_sha' => $this->sha,
            'author_name' => $authorName,
            'author_email' => $author['email'],
            'author_github' => $githubLogin,
            'edit_url' => $this->editUrl($path),
        ];
        $post->user_id = $author['email'] ? BackendUser::where('email', $author['email'])->value('id') : null;

        try {
            DB::transaction(function () use ($post, $sessionKey, $data, $path, $hash, $author) {
                $post->save(null, $sessionKey);
                $post->categories()->sync($this->categoryIds($data['categories']));

                Source::updateOrCreate(['source_path' => $path], [
                    'type' => Source::TYPE_POST,
                    'model_id' => $post->id,
                    'content_hash' => $hash,
                    'removed_at' => null,
                    'author_name' => $author['name'],
                    'author_email' => $author['email'],
                    'author_date' => $author['date'],
                ]);
            });
        } catch (Throwable $e) {
            $post->cancelDeferred($sessionKey);
            File::whereIn('id', $newFileIds ?: [0])->get()->each(fn ($file) => $file->delete());
            throw $e;
        }

        // Delete content images the post no longer references, and a replaced or removed teaser.
        $keep = array_merge($newFileIds, $keptFileIds);
        foreach ($post->content_images()->get()->merge($post->featured_images()->get()) as $file) {
            if (!in_array($file->id, $keep)) {
                $file->delete();
            }
        }

        $isNew ? $this->result->created++ : $this->result->updated++;

        if (!$isNew && $oldSlug !== $post->slug) {
            $this->createRedirect($oldSlug, $post->slug);
        }
    }

    /**
     * Attaches the post's relative images as public `content_images` files and returns the Markdown with their
     * URLs rewritten to the stored files.
     */
    protected function attachImages(
        Post $post,
        bool $isNew,
        string $body,
        array $images,
        string $sessionKey,
        array &$newFileIds,
        array &$keptFileIds
    ): string {
        $existing = $isNew ? collect() : $post->content_images()->get();
        $urls = [];

        foreach ($images as $image) {
            $repoPath = $image['path'];

            if ($repoPath === null || isset($urls[$image['url']])) {
                continue;
            }

            if (!isset($this->files[$repoPath])) {
                Log::warning(sprintf('Blogs MD: image "%s" doesn\'t exist in the repository; URL kept.', $repoPath));
                continue;
            }

            $file = $this->storeImage($post, 'content_images', $existing, $repoPath, $sessionKey, $newFileIds, $keptFileIds);

            if (!$file) {
                continue;
            }

            $urls[$image['url']] = $this->publicUrl($file);
        }

        if (empty($urls)) {
            return $body;
        }

        return preg_replace_callback(static::IMAGE_PATTERN, function ($m) use ($urls) {
            $url = trim($m[2], '<>');
            return isset($urls[$url]) ? $m[1] . $urls[$url] . $m[3] : $m[0];
        }, $body);
    }

    /**
     * The repository path of the post's `teaser` image, or null (with a warning) when it isn't a relative
     * path to a file in the repository.
     */
    protected function teaserPath(?string $teaser, string $path): ?string
    {
        if ($teaser === null) {
            return null;
        }

        $repoPath = $this->resolveRepoPath($teaser, $path);

        if ($repoPath === null || !isset($this->files[$repoPath])) {
            Log::warning(sprintf(
                'Blogs MD: %s: teaser "%s" isn\'t a relative path to a file in the repository; no teaser used.',
                $path,
                $teaser
            ));
            return null;
        }

        return $repoPath;
    }

    /**
     * The post's existing file for a repository image (same path and blob), or a new public File added to
     * `$relation` with the session key. Null, with a warning, when the file isn't an image.
     */
    protected function storeImage(
        Post $post,
        string $relation,
        $existing,
        string $repoPath,
        string $sessionKey,
        array &$newFileIds,
        array &$keptFileIds
    ): ?File {
        $blob = $this->files[$repoPath];
        $file = $existing->first(fn ($f) => $f->title === $repoPath && $f->description === $blob);

        if ($file) {
            $keptFileIds[] = $file->id;
            return $file;
        }

        $data = $this->git->read($this->sha, $repoPath);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);

        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
            Log::warning(sprintf('Blogs MD: "%s" is not an image (%s); not used.', $repoPath, $mime));
            return null;
        }

        $file = (new File)->fromData($data, basename($repoPath));
        $file->title = $repoPath;
        $file->description = $blob;
        $file->is_public = true;
        $file->save();
        $newFileIds[] = $file->id;

        $post->{$relation}()->add($file, $sessionKey);

        return $file;
    }

    /**
     * The file's URL without this site's scheme and host, so the stored HTML doesn't depend on `app.url`
     * (in the console, `URL::asset()` builds absolute URLs from it). URLs on another host, such as a
     * cloud disk, are kept as they are.
     */
    protected function publicUrl(File $file): string
    {
        $url = $file->getPath();
        $root = rtrim(URL::to('/'), '/');

        return str_starts_with($url, $root . '/') ? substr($url, strlen($root)) : $url;
    }

    /**
     * Finds Markdown images and resolves relative URLs against the post's folder in the repository.
     *
     * @return array<int, array{url: string, path: ?string}> `path` is null for absolute or external URLs, and for
     *     paths that leave the repository.
     */
    protected function findImages(string $body, string $path): array
    {
        preg_match_all(static::IMAGE_PATTERN, $body, $matches);

        $images = [];
        foreach ($matches[2] as $url) {
            $url = trim($url, '<>');
            $images[] = ['url' => $url, 'path' => $this->resolveRepoPath($url, $path)];
        }

        return $images;
    }

    protected function referencesRelativeImage(string $body): bool
    {
        foreach ($this->findImages($body, '') as $image) {
            if ($image['path'] !== null) {
                return true;
            }
        }

        return false;
    }

    protected function resolveRepoPath(string $url, string $fromPath): ?string
    {
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|/|\#)#i', $url)) {
            return null;
        }

        $url = rawurldecode(preg_replace('/[?#].*$/', '', $url));
        $directory = dirname($fromPath);
        $segments = $directory === '.' || $directory === '' ? [] : explode('/', $directory);

        foreach (explode('/', $url) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (empty($segments)) {
                    return null;
                }
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return empty($segments) ? null : implode('/', $segments);
    }

    /**
     * The post's author, stored on its source row once taken from the first commit.
     *
     * @return array{name: ?string, email: ?string, date: ?Carbon}
     */
    protected function author(?Source $source, string $path): array
    {
        if ($source && ($source->author_name !== null || $source->author_email !== null)) {
            return [
                'name' => $source->author_name,
                'email' => $source->author_email,
                'date' => $source->author_date ? Carbon::parse($source->author_date)->utc() : null,
            ];
        }

        $commit = $this->git->firstCommit($this->sha, $path);

        if (!$commit) {
            return ['name' => null, 'email' => null, 'date' => null];
        }

        $field = $this->config('author_field', 'author') === 'committer' ? 'committer' : 'author';

        return [
            'name' => $commit[$field . '_name'] ?: null,
            'email' => $commit[$field . '_email'] ?: null,
            'date' => Carbon::parse($commit['author_date'])->utc(),
        ];
    }

    protected function githubLogin(?string $email): ?string
    {
        if ($email && preg_match('/^\d+\+([^@]+)@users\.noreply\.github\.com$/i', $email, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Category ids for the given slugs, creating categories that have no file.
     */
    protected function categoryIds(array $slugs): array
    {
        $ids = [];

        foreach ($slugs as $slug) {
            $category = Category::where('slug', $slug)->first();

            if (!$category) {
                $category = new Category;
                $category->name = $this->generatedCategoryName($slug);
                $category->slug = $slug;
                $category->save();
            }

            $ids[] = $category->id;
        }

        return $ids;
    }

    protected function generatedCategoryName(string $slug): string
    {
        return Str::title(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Unpublishes or deletes posts whose source file is no longer in the repository.
     */
    protected function removePosts(array $present): void
    {
        $removed = Source::where('type', Source::TYPE_POST)
            ->whereNotIn('source_path', $present ?: [''])
            ->get();

        foreach ($removed as $source) {
            try {
                $post = Post::find($source->model_id);

                if ($this->config('on_removed', 'delete') === 'unpublish') {
                    if (!is_null($source->removed_at)) {
                        continue;
                    }

                    if ($post) {
                        $post->published = false;
                        $post->save();
                    }

                    $source->removed_at = Carbon::now();
                    $source->save();
                    $this->result->unpublished++;
                } else {
                    // Deleting the post deletes its attachments (attachMany defaults to delete => true).
                    $post?->delete();
                    $source->delete();
                    $this->result->deleted++;
                }
            } catch (Throwable $e) {
                $this->fail($source->source_path, $e);
            }
        }
    }

    /**
     * Adds a 301 from the post's old URL to its new one through Winter.Redirect, when installed.
     */
    protected function createRedirect(string $oldSlug, string $newSlug): void
    {
        if (!$this->config('redirect_on_move', true) || !PluginManager::instance()->exists('Winter.Redirect')) {
            return;
        }

        $prefix = rtrim((string) $this->config('post_url_prefix', '/blog'), '/');

        $redirect = new \Winter\Redirect\Models\Redirect;
        $redirect->fill([
            'match_type' => 'exact',
            'target_type' => 'path_or_url',
            'from_scheme' => 'auto',
            'to_scheme' => 'auto',
            'from_url' => $prefix . '/' . $oldSlug,
            'to_url' => $prefix . '/' . $newSlug,
            'status_code' => 301,
            'is_enabled' => true,
            'sort_order' => 0,
        ]);
        $redirect->save();

        // Winter.Redirect's observer, which publishes on save, is only registered in HTTP requests.
        if (App::runningInConsole() || App::runningUnitTests()) {
            Event::dispatch('winter.redirect.changed', ['redirectIds' => [$redirect->id]]);
        }
    }

    protected function editUrl(string $path): ?string
    {
        $repository = (string) $this->config('repository', '');

        if (!preg_match('#^https?://#', $repository)) {
            return null;
        }

        $base = preg_replace('#\.git$#', '', rtrim($repository, '/'));
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return $base . '/blob/' . $this->config('branch', 'master') . '/' . $encoded;
    }

    /**
     * Category files: direct children of the categories folder ending in `.md`.
     */
    protected function categoryFiles(): array
    {
        $prefix = trim((string) $this->config('categories_path', 'categories'), '/') . '/';

        return array_filter($this->files, function ($path) use ($prefix) {
            return str_starts_with($path, $prefix)
                && str_ends_with($path, '.md')
                && !str_contains(substr($path, strlen($prefix)), '/');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Post files: any `.md` file below the posts folder, at any depth.
     */
    protected function postFiles(): array
    {
        $prefix = trim((string) $this->config('posts_path', 'posts'), '/') . '/';

        return array_filter($this->files, function ($path) use ($prefix) {
            return str_starts_with($path, $prefix) && str_ends_with($path, '.md');
        }, ARRAY_FILTER_USE_KEY);
    }

    protected function logWarnings(SourceFile $parser): void
    {
        foreach ($parser->warnings as $warning) {
            Log::warning('Blogs MD: ' . $warning);
        }
    }

    protected function fail(string $path, Throwable $e): void
    {
        Log::error(sprintf('Blogs MD: %s at %s: %s', $path, $this->sha, $e->getMessage()));
        $this->result->failed[$path] = $e->getMessage();
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return Settings::value($key, $default);
    }
}
