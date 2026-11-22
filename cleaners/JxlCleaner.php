<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from JPEG XL files (.jxl) in the container format.
 *
 * Removed: "Exif", "xml " (XMP), "jumb" (JUMBF metadata, where C2PA lives)
 * and "brob" boxes whose payload is a brotli-compressed metadata box.
 * Each box is replaced by a "free" box of the same size, so no offset changes.
 * A bare codestream (no container) has no metadata and is left as it is.
 */
final class JxlCleaner implements CleanerInterface
{
    private const SIGNATURE = "\x00\x00\x00\x0CJXL \r\n\x87\n";

    /** Boxes that are metadata. */
    private const METADATA_BOXES = ['Exif', 'xml ', 'jumb'];

    /** brob payload types that are metadata once decompressed. */
    private const BROB_METADATA = ['Exif', 'xml ', 'jumb'];

    private const FREE_TYPES = ['free', 'skip'];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $fileSize = (int)fstat($fh)['size'];
            if ($fileSize < 12 || IsoBmff::readAt($fh, 0, 12) !== self::SIGNATURE) {
                // A bare codestream carries no metadata.
                if ($fileSize >= 2 && IsoBmff::readAt($fh, 0, 2) === "\xFF\x0A") {
                    return new CleanResult(FormatDetector::FORMAT_JPEG, false);
                }
                throw new CleanerException('Not a JPEG XL file.');
            }
            [$patches, $removed] = $this->plan($fh, $fileSize, $options);
        } finally {
            fclose($fh);
        }

        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_JPEG, false, $removed);
        }
        $this->applyPatches($path, $patches);

        return new CleanResult(FormatDetector::FORMAT_JPEG, true, $removed);
    }

    /**
     * @param resource $fh
     * @return array{0: array<int, array{size: int, large: bool}>, 1: MetadataBlock[]}
     */
    private function plan($fh, int $fileSize, CleanOptions $options): array
    {
        $patches = [];
        $removed = [];
        foreach (IsoBmff::topLevelBoxes($fh, $fileSize) as $box) {
            $type = $box['type'];
            $bodySize = $box['size'] - $box['header'];
            $isMetadata = in_array($type, self::METADATA_BOXES, true);
            if (!$isMetadata && $type === 'brob' && $bodySize >= 4) {
                $isMetadata = in_array(IsoBmff::readAt($fh, $box['start'] + $box['header'], 4), self::BROB_METADATA, true);
            }
            if ($isMetadata) {
                $payload = null;
                if ($options->collectPayloads && $bodySize <= $options->maxPayloadBytes) {
                    $payload = IsoBmff::readAt($fh, $box['start'] + $box['header'], $bodySize);
                }
                $removed[] = new MetadataBlock(
                    $type === 'Exif' ? MetadataBlock::TYPE_EXIF : ($type === 'xml ' ? MetadataBlock::TYPE_XMP : MetadataBlock::TYPE_OTHER),
                    IsoBmff::printable($type),
                    $box['size'],
                    $payload,
                );
                $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] >= 16];
                continue;
            }
            if (in_array($type, self::FREE_TYPES, true) && $bodySize > 0) {
                if (trim(IsoBmff::readAt($fh, $box['start'] + $box['header'], min(1048576, $bodySize)), "\x00") !== '') {
                    $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] >= 16];
                }
            }
        }

        return [$patches, $removed];
    }

    /**
     * @param array<int, array{size: int, large: bool}> $patches
     */
    private function applyPatches(string $path, array $patches): void
    {
        $writes = [];
        foreach ($patches as $offset => $patch) {
            $header = $patch['large']
                ? pack('N', 1) . 'free' . pack('J', $patch['size'])
                : pack('N', $patch['size']) . 'free';
            $writes[$offset] = $header;
            if ($patch['size'] > strlen($header)) {
                $writes[$offset + strlen($header)] = $patch['size'] - strlen($header);
            }
        }
        FilePatcher::apply($path, $writes);
    }
}
