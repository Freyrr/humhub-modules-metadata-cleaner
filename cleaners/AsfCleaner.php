<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from ASF files (WMV, WMA).
 *
 * Removed: the Content Description, Extended Content Description, Script
 * Command, Marker, Content Branding, Metadata and Metadata Library objects —
 * title, author, copyright, description and custom attributes.
 * The objects stay in place with their payload zeroed, so the file layout and
 * every offset are unchanged. The media data is never touched.
 */
final class AsfCleaner implements CleanerInterface
{
    /** Header object: the container of the other header objects. */
    private const GUID_HEADER = "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C";
    private const GUID_HEADER_EXT = "\xB5\x03\xBF\x5F\x2E\xA9\xCF\x11\x8E\xE3\x00\xC0\x0C\x20\x53\x65";
    private const GUID_DATA = "\x36\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C";
    private const GUID_SIMPLE_INDEX = "\x90\x08\x00\x33\xB1\xE5\xCF\x11\x89\xF4\x00\xA0\xC9\x03\x49\xCB";

    /** Objects whose payload is metadata and is zeroed. */
    private const METADATA_OBJECTS = [
        "\x33\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C", // Content Description
        "\x40\xA4\xD0\xD2\x07\xE3\xD2\x11\x97\xF0\x00\xA0\xC9\x5E\xA8\x50", // Extended Content Description
        "\x30\x1A\xFB\x1E\x62\x0B\xD0\x11\xA3\x9B\x00\xA0\xC9\x03\x48\xF6", // Script Command
        "\x01\xCD\x87\xF4\x51\xA9\xCF\x11\x8E\xE6\x00\xC0\x0C\x20\x53\x65", // Marker
        "\xFA\xB3\x11\x22\x23\xBD\xD2\x11\xB4\xB7\x00\xA0\xC9\x55\xFC\x6E", // Content Branding
        "\xEA\xCB\xF8\xC5\xAF\x5B\x77\x48\x84\x67\xAA\x8C\x44\xFA\x4C\xCA", // Metadata
        "\x94\x1C\x23\x44\x98\x94\xD1\x49\xA1\x41\x1D\x13\x4E\x45\x70\x54", // Metadata Library
    ];

    /** Padding object: kept, but its content is zeroed. */
    private const GUID_PADDING = "\x74\xD4\x06\x18\xDF\xCA\x09\x45\xA4\xBA\x9A\xAB\xCB\x96\xAA\xE8";

    private const OBJECT_HEADER = 24;

    /** Objects that are containers: reserved bytes then child objects. */
    private const CONTAINER_SKIP = [
        self::GUID_HEADER_EXT => 22,
    ];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $fileSize = (int)fstat($fh)['size'];
            [$patches, $removed] = $this->plan($fh, $fileSize, $options);
        } finally {
            fclose($fh);
        }

        if ($removed === [] && $patches === []) {
            return new CleanResult(FormatDetector::FORMAT_VIDEO, false);
        }
        if ($patches !== []) {
            FilePatcher::apply($path, $patches);
        }

        return new CleanResult(FormatDetector::FORMAT_VIDEO, true, $removed);
    }

    /**
     * @param resource $fh
     * @return array{0: array<int, int>, 1: MetadataBlock[]}
     */
    private function plan($fh, int $fileSize, CleanOptions $options): array
    {
        $head = IsoBmff::readAt($fh, 0, self::OBJECT_HEADER);
        if (strlen($head) < self::OBJECT_HEADER || substr($head, 0, 16) !== self::GUID_HEADER) {
            throw new CleanerException('Not an ASF file.');
        }
        $headerSize = unpack('P', $head, 16)[1];
        if ($headerSize < 30 || $headerSize > $fileSize) {
            throw new CleanerException('Invalid ASF header size.');
        }

        $patches = [];
        $removed = [];
        // The header object spans [0, $headerSize); its children start after
        // its own header (24 bytes), the object count and two reserved bytes.
        $this->walk($fh, self::OBJECT_HEADER + 6, $headerSize, 'header', $patches, $removed, $options);

        return [$patches, $removed];
    }

    /**
     * @param resource $fh
     * @param array<int, int> $patches offset => zero bytes
     * @param MetadataBlock[] $removed
     */
    private function walk($fh, int $start, int $end, string $path, array &$patches, array &$removed, CleanOptions $options): void
    {
        $pos = $start;
        while ($pos + self::OBJECT_HEADER <= $end) {
            $head = IsoBmff::readAt($fh, $pos, self::OBJECT_HEADER);
            $guid = substr($head, 0, 16);
            $size = unpack('P', $head, 16)[1];
            if ($size < self::OBJECT_HEADER || $pos + $size > $end) {
                throw new CleanerException(sprintf('Invalid ASF object at offset %d.', $pos));
            }
            $location = $path . '/' . self::guidName($guid);

            if (in_array($guid, self::METADATA_OBJECTS, true)) {
                $payloadSize = $size - self::OBJECT_HEADER;
                if ($this->hasNonZero($fh, $pos + self::OBJECT_HEADER, $payloadSize)) {
                    $payload = null;
                    if ($options->collectPayloads && $payloadSize <= $options->maxPayloadBytes) {
                        $payload = IsoBmff::readAt($fh, $pos + self::OBJECT_HEADER, $payloadSize);
                    }
                    $removed[] = new MetadataBlock(MetadataBlock::TYPE_TEXT, $location, $size, $payload);
                    $patches[$pos + self::OBJECT_HEADER] = $payloadSize;
                }
            } elseif ($guid === self::GUID_PADDING) {
                if ($this->hasNonZero($fh, $pos + self::OBJECT_HEADER, $size - self::OBJECT_HEADER)) {
                    $patches[$pos + self::OBJECT_HEADER] = $size - self::OBJECT_HEADER;
                }
            } elseif (isset(self::CONTAINER_SKIP[$guid])) {
                $this->walk($fh, $pos + self::OBJECT_HEADER + self::CONTAINER_SKIP[$guid], $pos + $size, $location, $patches, $removed, $options);
            }

            $pos += $size;
        }
    }

    /**
     * @param resource $fh
     */
    private function hasNonZero($fh, int $pos, int $length): bool
    {
        $end = $pos + $length;
        while ($pos < $end) {
            $chunk = IsoBmff::readAt($fh, $pos, min(1048576, $end - $pos));
            if (trim($chunk, "\x00") !== '') {
                return true;
            }
            $pos += strlen($chunk);
        }

        return false;
    }

    private static function guidName(string $guid): string
    {
        static $names = null;
        $names ??= array_merge(
            [self::GUID_HEADER => 'header', self::GUID_HEADER_EXT => 'headerExt', self::GUID_DATA => 'data', self::GUID_PADDING => 'padding'],
            array_combine(self::METADATA_OBJECTS, ['contentDescription', 'extContentDescription', 'scriptCommand', 'marker', 'contentBranding', 'metadata', 'metadataLibrary']),
        );

        return $names[$guid] ?? 'object';
    }
}
