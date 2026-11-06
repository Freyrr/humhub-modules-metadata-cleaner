<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Options shared by all cleaners.
 */
final class CleanOptions
{
    /** Default largest file a memory-bound cleaner may handle, in bytes. */
    public const DEFAULT_MAX_MEMORY_FILE_BYTES = 67108864;

    public function __construct(
        /** Keep the EXIF Orientation tag, so photos are not shown rotated. */
        public readonly bool $keepOrientation = true,
        /** Keep the raw bytes of removed blocks in the result, for the log. */
        public readonly bool $collectPayloads = false,
        /** Upper limit for a single collected payload, in bytes. */
        public readonly int $maxPayloadBytes = 1048576,
        /** Largest file a memory-bound cleaner may handle, in bytes.
         *  Bigger files are rejected instead of exhausting PHP memory. */
        public readonly int $maxMemoryFileBytes = self::DEFAULT_MAX_MEMORY_FILE_BYTES,
    ) {
    }
}
