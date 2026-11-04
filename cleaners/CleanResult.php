<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Outcome of a cleaning run.
 */
final class CleanResult
{
    /**
     * @param MetadataBlock[] $removed
     */
    public function __construct(
        /** Detected format, one of the FormatDetector::FORMAT_* constants, or null when not handled. */
        public readonly ?string $format,
        /** True when the file on disk was changed. */
        public readonly bool $changed,
        public readonly array $removed = [],
    ) {
    }

    public static function notHandled(): self
    {
        return new self(null, false);
    }

    public function hadMetadata(): bool
    {
        return $this->removed !== [];
    }
}
