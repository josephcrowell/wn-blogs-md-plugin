<?php

namespace JosephCrowell\BlogsMd\Classes;

use Symfony\Component\Process\Process;
use Winter\Storm\Support\Facades\File;

/**
 * Read-only access to the blog repository through a bare mirror clone.
 *
 * Every command runs through Symfony Process with an argument array; nothing goes through a shell.
 */
class GitRepository
{
    protected const TIMEOUT = 300;

    /**
     * How many renames `firstCommit()` follows back before giving up.
     */
    protected const MAX_RENAME_DEPTH = 100;

    protected string $directory;

    public function __construct(
        protected string $url,
        protected string $branch,
        protected ?string $token = null,
        ?string $directory = null
    ) {
        $this->directory = $directory ?? storage_path('temp/blogsmd/repo.git');
    }

    /**
     * Clones the mirror on first use (or when it is missing or corrupt), otherwise fetches the branch.
     */
    public function mirror(): void
    {
        if (!empty($this->token)) {
            $this->assertGitVersion('2.31.0');
        }

        if (!$this->isValidMirror()) {
            if (File::exists($this->directory)) {
                File::deleteDirectory($this->directory);
            }

            File::makeDirectory(dirname($this->directory), 0755, true, true);
            $this->run(['clone', '--bare', $this->url, $this->directory]);
            return;
        }

        $this->git([
            'fetch',
            '--prune',
            'origin',
            '+refs/heads/' . $this->branch . ':refs/heads/' . $this->branch,
        ]);
    }

    /**
     * The commit at the tip of the published branch.
     */
    public function headSha(): string
    {
        return trim($this->git(['rev-parse', '--verify', 'refs/heads/' . $this->branch . '^{commit}']));
    }

    /**
     * Every blob in the tree at the given commit, as `path => blob sha`.
     */
    public function listFiles(string $sha): array
    {
        $files = [];

        foreach (explode("\0", $this->git(['ls-tree', '-r', '-z', '--full-tree', $sha])) as $record) {
            if ($record === '' || !str_contains($record, "\t")) {
                continue;
            }

            [$meta, $path] = explode("\t", $record, 2);
            [, $type, $blob] = explode(' ', $meta);

            if ($type === 'blob') {
                $files[$path] = $blob;
            }
        }

        return $files;
    }

    /**
     * The raw contents of a file at the given commit.
     */
    public function read(string $sha, string $path): string
    {
        return $this->git(['cat-file', 'blob', $sha . ':' . $path]);
    }

    /**
     * Files renamed between two commits, as `old path => new path`. Returns none when the old commit is
     * unknown or no longer exists (for example after a force-push).
     */
    public function renames(?string $oldSha, string $newSha): array
    {
        if (empty($oldSha) || !$this->commitExists($oldSha)) {
            return [];
        }

        return $this->parseRenames($this->git(['diff', '-M', '-z', '--name-status', $oldSha, $newSha]));
    }

    /**
     * Rename records (`R<score>\0<old>\0<new>`) from `--name-status -z` output, as `old path => new path`.
     */
    protected function parseRenames(string $output): array
    {
        $tokens = explode("\0", $output);
        $renames = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $status = $tokens[$i];

            if ($status === '') {
                continue;
            }

            if ($status[0] === 'R' || $status[0] === 'C') {
                $old = $tokens[++$i] ?? null;
                $new = $tokens[++$i] ?? null;

                if ($status[0] === 'R' && $old !== null && $new !== null) {
                    $renames[$old] = $new;
                }
            } else {
                $i++;
            }
        }

        return $renames;
    }

    /**
     * Details of the commit that first added the file, following renames. Null when none is found.
     *
     * Only real renames are followed: the old path must be removed in the same commit that adds the new one.
     * `git log --follow` isn't used, because it also pairs a new file with a similar existing one, which
     * credited a separate post to an earlier post's author.
     *
     * @return array{author_name: string, author_email: string, author_date: string,
     *     committer_name: string, committer_email: string, sha: string}|null
     */
    public function firstCommit(string $sha, string $path): ?array
    {
        for ($depth = 0; $depth < static::MAX_RENAME_DEPTH; $depth++) {
            $output = $this->git([
                'log',
                '--diff-filter=A',
                '--format=%an%x1f%ae%x1f%aI%x1f%cn%x1f%ce%x1f%H',
                $sha,
                '--',
                $path,
            ]);

            $lines = array_values(array_filter(explode("\n", $output), fn ($line) => trim($line) !== ''));

            if (empty($lines)) {
                return null;
            }

            $fields = explode("\x1f", end($lines));

            if (count($fields) < 6) {
                return null;
            }

            $commit = array_combine(
                ['author_name', 'author_email', 'author_date', 'committer_name', 'committer_email', 'sha'],
                array_slice($fields, 0, 6)
            );

            $renamedFrom = array_search($path, $this->renamesIn($commit['sha']), true);

            if ($renamedFrom === false) {
                return $commit;
            }

            $sha = $commit['sha'] . '^';
            $path = $renamedFrom;
        }

        return null;
    }

    /**
     * Files renamed by one commit (against its first parent), as `old path => new path`.
     */
    protected function renamesIn(string $commit): array
    {
        return $this->parseRenames($this->git([
            'diff-tree',
            '--no-commit-id',
            '--root',
            '-r',
            '-M',
            '-z',
            '--name-status',
            $commit,
        ]));
    }

    protected function isValidMirror(): bool
    {
        if (!File::isDirectory($this->directory)) {
            return false;
        }

        try {
            $this->git(['rev-parse', '--git-dir']);
            $this->git(['rev-parse', '--verify', 'refs/heads/' . $this->branch . '^{commit}']);

            // A mirror of a different repository (the configured URL changed) is re-cloned.
            return trim($this->git(['config', '--get', 'remote.origin.url'])) === $this->url;
        } catch (GitException $e) {
            return false;
        }
    }

    protected function commitExists(string $sha): bool
    {
        try {
            $this->git(['cat-file', '-e', $sha . '^{commit}']);
            return true;
        } catch (GitException $e) {
            return false;
        }
    }

    protected function assertGitVersion(string $minimum): void
    {
        $output = $this->run(['--version']);

        if (!preg_match('/(\d+\.\d+(?:\.\d+)?)/', $output, $matches) || version_compare($matches[1], $minimum, '<')) {
            throw new GitException(sprintf(
                'git %s or newer is required to use an access token; found "%s".',
                $minimum,
                trim($output)
            ));
        }
    }

    /**
     * Runs a git command against the mirror.
     */
    protected function git(array $arguments): string
    {
        return $this->run(array_merge(['-C', $this->directory], $arguments));
    }

    /**
     * Runs git with the given arguments and returns stdout. Throws on a non-zero exit.
     */
    protected function run(array $arguments): string
    {
        $process = new Process(array_merge(['git'], $arguments), null, $this->environment(), null, static::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new GitException(sprintf(
                'git %s failed (exit %s): %s',
                implode(' ', $arguments),
                $process->getExitCode(),
                trim($process->getErrorOutput())
            ));
        }

        return $process->getOutput();
    }

    /**
     * Environment for git processes. The token travels only here, as an HTTP header.
     */
    protected function environment(): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            'LC_ALL' => 'C',
        ];

        if (!empty($this->token)) {
            $env += [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode('x-access-token:' . $this->token),
            ];
        }

        return $env;
    }
}
