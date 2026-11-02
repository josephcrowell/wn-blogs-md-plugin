<?php

namespace JosephCrowell\BlogsMd\Console;

use JosephCrowell\BlogsMd\Classes\SyncRunner;
use Throwable;
use Winter\Storm\Console\Command;

/**
 * Syncs Winter.Blog posts and categories from the blog repository.
 */
class Sync extends Command
{
    /**
     * @var string The name and signature of this command.
     */
    protected $signature = 'blogsmd:sync
        {--force : Re-sync every file, even when the repository hasn\'t changed.}';

    /**
     * @var string The console command description.
     */
    protected $description = 'Syncs blog posts and categories from the Markdown files in the blog repository.';

    public function handle(): int
    {
        try {
            $result = (new SyncRunner)->run((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info($result->summary());

        foreach ($result->failed as $path => $message) {
            $this->error($path . ': ' . $message);
        }

        return $result->hasFailures() ? 1 : 0;
    }
}
