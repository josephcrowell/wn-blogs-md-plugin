<?php

namespace JosephCrowell\BlogsMd\Classes;

use Illuminate\Support\Facades\Log;
use Throwable;
use Winter\Storm\Network\Http;

/**
 * Finds the GitHub account behind a commit, through GitHub's REST API ("Get a commit"), which matches the
 * commit's email to an account.
 *
 * Requests use Winter's curl-based `Winter\Storm\Network\Http`, not Laravel's HTTP client, which needs
 * Guzzle (not installed on every Winter site).
 */
class GitHubUsers
{
    public function __construct(
        protected string $repository,
        protected ?string $token = null
    ) {
    }

    /**
     * The `owner/name` of a GitHub repository URL, or null for other hosts.
     */
    public static function repositoryName(string $url): ?string
    {
        if (preg_match('#github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$#i', $url, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        return null;
    }

    /**
     * The GitHub login of the commit's author (or committer), or null when the repository isn't on GitHub, the
     * email isn't linked to an account, or the request fails (logged).
     */
    public function loginForCommit(string $sha, string $field = 'author'): ?string
    {
        $name = static::repositoryName($this->repository);

        if ($name === null) {
            return null;
        }

        try {
            [$status, $body] = $this->request('https://api.github.com/repos/' . $name . '/commits/' . rawurlencode($sha));
        } catch (Throwable $e) {
            Log::warning(sprintf('Blogs MD: looking up the GitHub account of commit %s failed: %s', $sha, $e->getMessage()));
            return null;
        }

        if ($status < 200 || $status >= 300) {
            Log::warning(sprintf('Blogs MD: looking up the GitHub account of commit %s failed: HTTP %d', $sha, $status));
            return null;
        }

        $data = json_decode($body, true);
        $login = $data[$field === 'committer' ? 'committer' : 'author']['login'] ?? null;

        return is_string($login) && $login !== '' ? $login : null;
    }

    /**
     * Sends a GET request to GitHub's API and returns `[status code, body]`.
     */
    protected function request(string $url): array
    {
        $response = Http::get($url, function ($http) {
            $http->header('Accept', 'application/vnd.github+json');
            $http->header('X-GitHub-Api-Version', '2022-11-28');
            $http->header('User-Agent', 'wn-blogs-md-plugin');
            $http->timeout(10);

            if (!empty($this->token)) {
                $http->header('Authorization', 'Bearer ' . $this->token);
            }
        });

        return [(int) $response->code, (string) $response->body];
    }
}
