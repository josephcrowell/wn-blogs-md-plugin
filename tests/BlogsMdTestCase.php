<?php

namespace JosephCrowell\BlogsMd\Tests;

use JosephCrowell\BlogsMd\Classes\GitRepository;
use JosephCrowell\BlogsMd\Classes\SyncResult;
use JosephCrowell\BlogsMd\Classes\SyncRunner;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Winter\Blog\Models\Settings as BlogSettings;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Storm\Support\Facades\Config;
use Winter\Storm\Support\Facades\File;
use Winter\Storm\Support\Str;

/**
 * Builds a throwaway Git repository with the blog layout and syncs it through a private mirror.
 */
abstract class BlogsMdTestCase extends PluginTestCase
{
    protected string $repo;
    protected string $mirror;

    public function setUp(): void
    {
        parent::setUp();

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        // Post implements Winter.Translate's behaviour, which needs its tables (installed on the site, but not in
        // this plugin's $require, so PluginTestCase doesn't migrate it).
        $this->instantiatePlugin('Winter.Translate');

        // Winter.Blog seeds a sample post and category; settings instances are cached between tests.
        DB::table('winter_blog_posts_categories')->delete();
        DB::table('winter_blog_posts')->delete();
        DB::table('winter_blog_categories')->delete();
        BlogSettings::resetDefault();
        \System\Behaviors\SettingsModel::clearInternalCache();

        $base = sys_get_temp_dir() . '/blogsmd-test-' . Str::random(12);
        $this->repo = $base . '/source';
        $this->mirror = $base . '/mirror/repo.git';
        File::makeDirectory($this->repo, 0755, true);

        $this->git(['init', '-q', '-b', 'master']);

        Config::set('josephcrowell.blogsmd::repository', $this->repo);
        Config::set('josephcrowell.blogsmd::branch', 'master');
        Config::set('josephcrowell.blogsmd::posts_path', 'posts');
        Config::set('josephcrowell.blogsmd::categories_path', 'categories');
        Config::set('josephcrowell.blogsmd::author_field', 'author');
        Config::set('josephcrowell.blogsmd::on_removed', 'delete');
        Config::set('josephcrowell.blogsmd::redirect_on_move', true);
        Config::set('josephcrowell.blogsmd::post_url_prefix', '/blog');
        Config::set('josephcrowell.blogsmd::token', '');
    }

    public function tearDown(): void
    {
        if (isset($this->repo)) {
            File::deleteDirectory(dirname($this->repo));
        }

        parent::tearDown();
    }

    /**
     * Runs a sync against the fixture repository, using the test's private mirror directory.
     */
    protected function sync(bool $force = false): SyncResult
    {
        $mirror = $this->mirror;

        return (new class ($mirror) extends SyncRunner {
            public function __construct(protected string $mirror)
            {
            }

            protected function makeRepository(string $repository): GitRepository
            {
                return new GitRepository($repository, 'master', null, $this->mirror);
            }
        })->run($force);
    }

    protected function write(string $path, string $contents): void
    {
        $full = $this->repo . '/' . $path;
        File::makeDirectory(dirname($full), 0755, true, true);
        File::put($full, $contents);
    }

    protected function remove(string $path): void
    {
        $this->git(['rm', '-q', $path]);
    }

    protected function move(string $from, string $to): void
    {
        File::makeDirectory(dirname($this->repo . '/' . $to), 0755, true, true);
        $this->git(['mv', $from, $to]);
    }

    /**
     * Commits everything with the given author; the committer defaults to the author.
     */
    protected function commit(
        string $message = 'Update',
        string $authorName = 'Ada Author',
        string $authorEmail = 'ada@example.org',
        ?string $committerName = null,
        ?string $committerEmail = null
    ): string {
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '--allow-empty', '-m', $message], [
            'GIT_AUTHOR_NAME' => $authorName,
            'GIT_AUTHOR_EMAIL' => $authorEmail,
            'GIT_AUTHOR_DATE' => '2026-10-01T12:00:00+00:00',
            'GIT_COMMITTER_NAME' => $committerName ?? $authorName,
            'GIT_COMMITTER_EMAIL' => $committerEmail ?? $authorEmail,
            'GIT_COMMITTER_DATE' => '2026-10-01T12:00:00+00:00',
        ]);

        return trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function git(array $arguments, array $env = []): string
    {
        $process = new Process(array_merge(['git', '-C', $this->repo], $arguments), null, $env + [
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.org',
            'GIT_COMMITTER_NAME' => 'Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.org',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * A post file in the plugin's format.
     */
    protected function postFile(string $title, string $body = "Some content.\n", array $settings = []): string
    {
        $lines = ['title = "' . $title . '"'];
        foreach ($settings as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n;;\n" . $body;
    }

    /**
     * A small PNG in the given colour.
     */
    protected function png(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }
}
