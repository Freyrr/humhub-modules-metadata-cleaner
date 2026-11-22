<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from one file format without re-encoding the content.
 *
 * Implementations work on a file path and change the file in place.
 * They must never leave a half-written file behind: either the file is
 * fully cleaned, or it is untouched and a CleanerException is thrown.
 */
interface CleanerInterface
{
    /**
     * @throws CleanerException when the file is broken or cannot be cleaned safely
     */
    public function clean(string $path, CleanOptions $options): CleanResult;
}
