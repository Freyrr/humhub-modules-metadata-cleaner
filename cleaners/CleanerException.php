<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use RuntimeException;

/**
 * Thrown when a file cannot be cleaned. The upload must then be rejected.
 */
class CleanerException extends RuntimeException
{
}
