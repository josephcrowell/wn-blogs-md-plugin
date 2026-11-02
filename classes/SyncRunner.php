<?php

namespace JosephCrowell\BlogsMd\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JosephCrowell\BlogsMd\Models\Settings;
use JosephCrowell\BlogsMd\Models\SyncState;
use RuntimeException;
use Throwable;
use Winter\Blog\Models\Settings as BlogSettings;

/**
 * Runs one sync of the blog repository. Shared by the console command, the schedule and the webhook.
 */
class SyncRunner
{
    public function run(bool $force = false): SyncResult
    {
        $repository = (string) Settings::value('repository', '');

        if ($repository === '') {
            throw new RuntimeException('Blogs MD: no repository is configured (Settings > Blogs MD).');
        }

        $lock = Cache::lock('blogsmd.sync', 900);

        if (!$lock->get()) {
            Log::info('Blogs MD: a sync is already running.');
            return SyncResult::withStatus(SyncResult::STATUS_LOCKED);
        }

        $state = SyncState::current();

        try {
            if (BlogSettings::get('use_rich_editor', false)) {
                throw new RuntimeException(
                    'Blogs MD: Winter.Blog\'s rich editor setting is on, so post content would be treated as HTML. '
                    . 'Turn it off under Settings > Blog before syncing.'
                );
            }

            $git = $this->makeRepository($repository);
            $git->mirror();
            $head = $git->headSha();

            if ($head === $state->processed_sha && !$force) {
                return SyncResult::withStatus(SyncResult::STATUS_UP_TO_DATE);
            }

            $result = new SyncResult;
            $result->sha = $head;

            $synchroniser = new Synchroniser($git, $head, $git->listFiles($head), $result, $force);
            $synchroniser->syncCategories();
            $synchroniser->syncPosts($git->renames($state->processed_sha, $head));
            $synchroniser->removeCategories();

            if ($result->hasFailures()) {
                $state->last_error = $this->describeFailures($result);
            } else {
                $state->processed_sha = $head;
                $state->last_error = null;
            }

            $state->processed_at = Carbon::now();
            $state->save();

            Log::info('Blogs MD: ' . $result->summary());

            return $result;
        } catch (Throwable $e) {
            Log::error('Blogs MD: sync failed: ' . $e->getMessage(), ['exception' => $e]);
            $state->last_error = $e->getMessage();
            $state->processed_at = Carbon::now();
            $state->save();
            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function makeRepository(string $repository): GitRepository
    {
        return new GitRepository(
            $repository,
            (string) Settings::value('branch', 'master'),
            Settings::value('token') ?: null
        );
    }

    protected function describeFailures(SyncResult $result): string
    {
        $lines = [];
        foreach ($result->failed as $path => $message) {
            $lines[] = $path . ': ' . $message;
        }

        return implode("\n", $lines);
    }
}
