<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes EXIF and XMP from HEIF images (HEIC, AVIF).
 *
 * In HEIF, EXIF and XMP are "items" listed in the meta box, whose bytes live
 * in mdat or idat. Taking items out of the structure would shift the offsets
 * of every other item, so instead each metadata item is neutralised in place:
 * - its bytes are overwritten with zeros;
 * - its entry in iinf is marked hidden and its type changed to one no reader
 *   knows, so readers skip it as the HEIF standard requires for unknown items.
 * Nothing changes size, so the image items stay exactly where they were.
 * Rotation and mirroring live in item properties (irot/imir) and are kept.
 * The short video of motion photos, stored in a top-level "mpvd" box, is
 * removed the same way: the box becomes a zero-filled "free" box.
 */
final class HeifCleaner implements CleanerInterface
{
    /** Item type written over neutralised metadata items. */
    public const NEUTRAL_TYPE = 'mdcz';

    /** Largest meta box loaded in memory. */
    private const MAX_META_BYTES = 67108864;

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
        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_HEIF, false, $removed);
        }
        FilePatcher::apply($path, $patches);

        return new CleanResult(FormatDetector::FORMAT_HEIF, true, $removed);
    }

    /**
     * @param resource $fh
     * @return array{0: array<int, string|int>, 1: MetadataBlock[]}
     */
    private function plan($fh, int $fileSize, CleanOptions $options): array
    {
        $top = IsoBmff::topLevelBoxes($fh, $fileSize);
        $metas = array_values(array_filter($top, static fn($b) => $b['type'] === 'meta'));
        if (count($metas) !== 1) {
            throw new CleanerException(sprintf('Expected one "meta" box, found %d.', count($metas)));
        }
        $metaBox = $metas[0];
        if ($metaBox['size'] > self::MAX_META_BYTES) {
            throw new CleanerException('The "meta" box is too large.');
        }
        $meta = IsoBmff::readAt($fh, $metaBox['start'], $metaBox['size']);
        $children = IsoBmff::boxes($meta, $metaBox['header'] + 4, $metaBox['size']);

        $iinf = $this->child($children, 'iinf');
        $iloc = $this->child($children, 'iloc');
        $idat = $this->child($children, 'idat');
        if ($iinf === null || $iloc === null) {
            throw new CleanerException('HEIF meta box without iinf or iloc.');
        }
        $items = $this->readItemInfos($meta, $iinf);
        $locations = $this->readItemLocations($meta, $iloc);
        $idatStart = $idat !== null ? $metaBox['start'] + $idat['start'] + $idat['header'] : null;

        $patches = [];
        $removed = [];
        foreach ($items as $id => $item) {
            $kind = $this->metadataKind($item);
            if ($kind === null) {
                continue;
            }
            $location = $locations[$id] ?? null;
            $payload = '';
            if ($location !== null) {
                if ($location['method'] === 1 && $idatStart === null) {
                    throw new CleanerException('Item stored in a missing idat box.');
                }
                if ($location['method'] > 1) {
                    throw new CleanerException('Unsupported item construction method.');
                }
                $base = $location['method'] === 1 ? $idatStart + $location['base'] : $location['base'];
                foreach ($location['extents'] as [$offset, $length]) {
                    if ($length === 0 || $base + $offset + $length > $fileSize) {
                        throw new CleanerException('Invalid metadata item extent.');
                    }
                    if ($options->collectPayloads && strlen($payload) + $length <= $options->maxPayloadBytes) {
                        $payload .= IsoBmff::readAt($fh, $base + $offset, $length);
                    }
                    $patches[$base + $offset] = $length;
                }
            }
            // Mark the entry hidden (flags bit 0) and give it an unknown type.
            $abs = $metaBox['start'] + $item['start'];
            $patches[$abs + $item['flagsPos']] = "\x01";
            $patches[$abs + $item['typePos']] = self::NEUTRAL_TYPE;

            if ($kind === MetadataBlock::TYPE_EXIF && strlen($payload) >= 4) {
                // Exif items start with the offset of the TIFF header.
                $payload = substr($payload, 4 + unpack('N', $payload)[1]);
            }
            $removed[] = new MetadataBlock(
                $kind,
                'item:' . $id . ':' . $item['type'],
                array_sum(array_column($location['extents'] ?? [], 1)),
                $options->collectPayloads ? $payload : null,
            );
        }

        foreach ($top as $box) {
            if ($box['type'] === 'uuid' && $box['uuid'] === IsoBmff::XMP_UUID) {
                $patches[$box['start'] + $box['header']] = $box['size'] - $box['header'];
                $removed[] = new MetadataBlock(MetadataBlock::TYPE_XMP, 'uuid:XMP', $box['size']);
            }
            // Motion photos: the video (with its own metadata) is appended in an "mpvd" box.
            if ($box['type'] === 'mpvd') {
                $patches[$box['start'] + 4] = 'free';
                if ($box['size'] > $box['header']) {
                    $patches[$box['start'] + $box['header']] = $box['size'] - $box['header'];
                }
                $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'mpvd:MotionPhotoVideo', $box['size']);
            }
        }

        return [$patches, $removed];
    }

    private function metadataKind(array $item): ?string
    {
        if ($item['type'] === 'Exif') {
            return MetadataBlock::TYPE_EXIF;
        }
        if ($item['type'] === 'mime' && preg_match('/xmp|rdf\+xml/i', $item['contentType'])) {
            return MetadataBlock::TYPE_XMP;
        }

        return null;
    }

    private function child(array $boxes, string $type): ?array
    {
        $found = array_values(array_filter($boxes, static fn($b) => $b['type'] === $type));
        if (count($found) > 1) {
            throw new CleanerException(sprintf('More than one "%s" box.', $type));
        }

        return $found[0] ?? null;
    }

    /**
     * @return array<int, array{type: string, contentType: string, start: int, flagsPos: int, typePos: int}>
     *     start = offset of the infe box in the meta data
     */
    private function readItemInfos(string $meta, array $iinf): array
    {
        $p = $iinf['start'] + $iinf['header'];
        $end = $iinf['start'] + $iinf['size'];
        $version = ord($meta[$p]);
        $p += 4 + ($version === 0 ? 2 : 4);

        $items = [];
        foreach (IsoBmff::boxes($meta, $p, $end) as $infe) {
            if ($infe['type'] !== 'infe') {
                continue;
            }
            $q = $infe['start'] + $infe['header'];
            $infeEnd = $infe['start'] + $infe['size'];
            $infeVersion = ord($meta[$q]);
            if ($infeVersion < 2) {
                // Version 0/1 entries have no item type: they are never EXIF/XMP items.
                continue;
            }
            $idSize = $infeVersion === 2 ? 2 : 4;
            if ($q + 4 + $idSize + 2 + 4 > $infeEnd) {
                throw new CleanerException('Truncated infe box.');
            }
            $id = $idSize === 2 ? unpack('n', $meta, $q + 4)[1] : unpack('N', $meta, $q + 4)[1];
            $typePos = $q + 4 + $idSize + 2;
            $type = substr($meta, $typePos, 4);
            $strings = explode("\x00", substr($meta, $typePos + 4, $infeEnd - $typePos - 4));
            $items[$id] = [
                'type' => $type,
                'contentType' => $type === 'mime' ? ($strings[1] ?? '') : '',
                'start' => $infe['start'],
                'flagsPos' => $q + 3 - $infe['start'],
                'typePos' => $typePos - $infe['start'],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{method: int, base: int, extents: list<array{0: int, 1: int}>}>
     */
    private function readItemLocations(string $meta, array $iloc): array
    {
        $p = $iloc['start'] + $iloc['header'];
        $end = $iloc['start'] + $iloc['size'];
        $version = ord($meta[$p]);
        if ($version > 2 || $p + 8 > $end) {
            throw new CleanerException('Unsupported iloc box.');
        }
        $p += 4;
        $sizes = ord($meta[$p]);
        $offsetSize = $sizes >> 4;
        $lengthSize = $sizes & 0x0F;
        $sizes2 = ord($meta[$p + 1]);
        $baseSize = $sizes2 >> 4;
        $indexSize = $version > 0 ? $sizes2 & 0x0F : 0;
        $p += 2;
        $count = $this->uint($meta, $p, $version < 2 ? 2 : 4, $end);

        $locations = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $this->uint($meta, $p, $version < 2 ? 2 : 4, $end);
            $method = 0;
            if ($version > 0) {
                $method = $this->uint($meta, $p, 2, $end) & 0x0F;
            }
            $this->uint($meta, $p, 2, $end); // data_reference_index
            $base = $this->uint($meta, $p, $baseSize, $end);
            $extentCount = $this->uint($meta, $p, 2, $end);
            $extents = [];
            for ($e = 0; $e < $extentCount; $e++) {
                $this->uint($meta, $p, $indexSize, $end);
                $offset = $this->uint($meta, $p, $offsetSize, $end);
                $length = $this->uint($meta, $p, $lengthSize, $end);
                $extents[] = [$offset, $length];
            }
            $locations[$id] = ['method' => $method, 'base' => $base, 'extents' => $extents];
        }

        return $locations;
    }

    /**
     * Reads an unsigned big-endian integer of 0, 2, 4 or 8 bytes and moves $p.
     */
    private function uint(string $data, int &$p, int $size, int $end): int
    {
        if ($size === 0) {
            return 0;
        }
        if (!in_array($size, [1, 2, 4, 8], true) || $p + $size > $end) {
            throw new CleanerException('Invalid iloc box.');
        }
        $value = match ($size) {
            1 => ord($data[$p]),
            2 => unpack('n', $data, $p)[1],
            4 => unpack('N', $data, $p)[1],
            8 => unpack('J', $data, $p)[1],
        };
        $p += $size;

        return $value;
    }
}
