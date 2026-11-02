<?php

namespace JosephCrowell\BlogsMd\Classes;

use RuntimeException;

/**
 * A git command failed. The message carries the arguments and stderr; the access token is never part of either.
 */
class GitException extends RuntimeException
{
}
