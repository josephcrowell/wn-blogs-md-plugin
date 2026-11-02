<?php

namespace JosephCrowell\BlogsMd\Classes;

/**
 * Outcome of a sync run.
 */
class SyncResult
{
    public const STATUS_SYNCED = 'synced';
    public const STATUS_UP_TO_DATE = 'up to date';
    public const STATUS_LOCKED = 'locked';

    public string $status = self::STATUS_SYNCED;
    public ?string $sha = null;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $unpublished = 0;
    public int $deleted = 0;
    public int $categories = 0;

    /**
     * @var array Failed files, as `path => message`.
     */
    public array $failed = [];

    public static function withStatus(string $status): static
    {
        $result = new static;
        $result->status = $status;
        return $result;
    }

    public function hasFailures(): bool
    {
        return !empty($this->failed);
    }

    public function summary(): string
    {
        if ($this->status !== static::STATUS_SYNCED) {
            return ucfirst($this->status) . '.';
        }

        return sprintf(
            'Synced %s: %d created, %d updated, %d unchanged, %d unpublished, %d deleted, %d categories, %d failed.',
            $this->sha ? substr($this->sha, 0, 12) : '(none)',
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->unpublished,
            $this->deleted,
            $this->categories,
            count($this->failed)
        );
    }
}
