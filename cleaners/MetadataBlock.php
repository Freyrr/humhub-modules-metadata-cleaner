<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * One block of metadata that a cleaner removed from a file.
 */
final class MetadataBlock
{
    public const TYPE_EXIF = 'exif';
    public const TYPE_XMP = 'xmp';
    public const TYPE_IPTC = 'iptc';
    public const TYPE_COMMENT = 'comment';
    public const TYPE_TEXT = 'text';
    public const TYPE_QUICKTIME = 'quicktime';
    public const TYPE_OTHER = 'other';

    public function __construct(
        /** One of the TYPE_* constants. */
        public readonly string $type,
        /** Where the block was found, e.g. "APP1", "tEXt:Author", "moov/udta/©xyz". */
        public readonly string $location,
        /** Size of the removed block in bytes. */
        public readonly int $size,
        /** Raw bytes of the block, only when CleanOptions::$collectPayloads is set. */
        public readonly ?string $payload = null,
        /**
         * Field name => value pairs already decoded by the cleaner, for formats
         * whose metadata the generic readers do not understand (QuickTime).
         * Only filled when CleanOptions::$collectPayloads is set.
         *
         * @var array<string, string>
         */
        public readonly array $fields = [],
    ) {
    }
}
