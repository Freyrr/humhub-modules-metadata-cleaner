<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from JPEG 2000 files (.jp2, .jpx, .jpm, .jpf).
 *
 * Removed: "uuid" boxes (EXIF, XMP and vendor data), "xml " boxes, "asoc"
 * associations and "lbl " labels — at top level and inside "jp2h". Each box
 * is replaced by a "free" box of the same size, so no offset changes.
 * "jp2h" children and the "jp2c" codestream are never touched.
 */
final class Jp2Cleaner implements CleanerInterface
{
    private const SIGNATURE = "\x00\x00\x00\x0CjP  \r\n\x87\n";

    /** Boxes holding metadata. */
    private const METADATA_BOXES = ['uuid', 'xml ', 'asoc', 'lbl '];

    /** Kept, but their content is zeroed (old metadata often hides there). */
    private const FREE_TYPES = ['free', 'skip'];

    private const CONTAINERS = ['jp2h'];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $fileSize = (int)fstat($fh)['size'];
            if ($fileSize < 12 || IsoBmff::readAt($fh, 0, 12) !== self::SIGNATURE) {
                throw new CleanerException('Not a JPEG 2000 file.');
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
            $this->planBox($fh, $box, '', $patches, $removed, $options);
        }

        return [$patches, $removed];
    }

    /**
     * @param resource $fh
     * @param array<int, array{size: int, large: bool}> $patches
     * @param MetadataBlock[] $removed
     */
    private function planBox($fh, array $box, string $path, array &$patches, array &$removed, CleanOptions $options): void
    {
        $type = $box['type'];
        $location = $path === '' ? IsoBmff::printable($type) : $path . '/' . IsoBmff::printable($type);
        if (in_array($type, self::METADATA_BOXES, true)) {
            $payload = null;
            $bodySize = $box['size'] - $box['header'];
            if ($options->collectPayloads && $bodySize <= $options->maxPayloadBytes) {
                $payload = IsoBmff::readAt($fh, $box['start'] + $box['header'], $bodySize);
            }
            $removed[] = new MetadataBlock(
                $type === 'xml ' ? MetadataBlock::TYPE_XMP : MetadataBlock::TYPE_OTHER,
                $location,
                $box['size'],
                $payload,
            );
            $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] >= 16];
            return;
        }
        if (in_array($type, self::FREE_TYPES, true) && $box['size'] > $box['header']) {
            $body = IsoBmff::readAt($fh, $box['start'] + $box['header'], min(1048576, $box['size'] - $box['header']));
            if (trim($body, "\x00") !== '') {
                $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] >= 16];
            }
            return;
        }
        if (in_array($type, self::CONTAINERS, true)) {
            $body = IsoBmff::readAt($fh, $box['start'] + $box['header'], $box['size'] - $box['header']);
            foreach (IsoBmff::boxes($body, 0, strlen($body)) as $child) {
                $child['start'] += $box['start'] + $box['header'];
                $this->planBox($fh, $child, $location, $patches, $removed, $options);
            }
        }
    }

    /**
     * Replaces each planned range with a zero-filled "free" box of the same size.
     *
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
