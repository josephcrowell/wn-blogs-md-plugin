<?php

namespace JosephCrowell\BlogsMd\Classes;

use RuntimeException;

/**
 * A post or category file can't be used. The previous synced version stays live.
 */
class SourceException extends RuntimeException
{
}
