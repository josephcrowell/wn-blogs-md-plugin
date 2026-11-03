<?php

namespace JosephCrowell\BlogsMd\Classes;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JosephCrowell\BlogsMd\Models\Settings;
use Throwable;

/**
 * Receives GitHub push webhooks for the blog repository.
 */
class GitHubWebhook
{
    public function handle(Request $request): Response
    {
        $secret = (string) Settings::value('webhook_secret', '');

        if ($secret === '') {
            Log::error('Blogs MD webhook: no webhook secret is configured (Settings > Blogs MD); request refused.');
            return new Response('Webhook not configured', 500);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return new Response('Invalid signature', 401);
        }

        $event = $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return new Response('pong', 200);
        }

        if ($event === 'push') {
            $payload = json_decode($request->getContent(), true) ?: [];
            $branch = (string) Settings::value('branch', 'master');
            $repository = $this->repositoryName((string) Settings::value('repository', ''));
            $fullName = (string) ($payload['repository']['full_name'] ?? '');

            if (
                ($payload['ref'] ?? null) === 'refs/heads/' . $branch
                && $repository !== null
                && strcasecmp($repository, $fullName) === 0
            ) {
                dispatch(function () {
                    @set_time_limit(900);
                    try {
                        (new SyncRunner)->run(false);
                    } catch (Throwable $e) {
                        Log::error('Blogs MD webhook: sync failed: ' . $e->getMessage());
                    }
                })->afterResponse();
            }
        }

        return new Response('Accepted', 202);
    }

    /**
     * The `owner/name` of a GitHub repository URL, or null when it isn't one.
     */
    protected function repositoryName(string $url): ?string
    {
        return GitHubUsers::repositoryName($url);
    }
}
