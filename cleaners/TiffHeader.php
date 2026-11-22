<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Minimal reader and writer for the TIFF structure used inside EXIF blocks.
 * It only knows what the cleaners need: the tags of the first IFD and the
 * Orientation value.
 */
final class TiffHeader
{
    public const TAG_ORIENTATION = 0x0112;

    /**
     * Returns the tag ids of IFD0 and whether a next IFD follows, or null when
     * the data is not valid TIFF.
     *
     * @return array{tags: array<int, array{type: int, count: int, value: string}>, hasNext: bool, order: string}|null
     */
    public static function readIfd0(string $tiff): ?array
    {
        $len = strlen($tiff);
        if ($len < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order === 'II') {
            $u16 = 'v';
            $u32 = 'V';
        } elseif ($order === 'MM') {
            $u16 = 'n';
            $u32 = 'N';
        } else {
            return null;
        }
        if (unpack($u16, $tiff, 2)[1] !== 42) {
            return null;
        }
        $offset = unpack($u32, $tiff, 4)[1];
        if ($offset < 8 || $offset + 2 > $len) {
            return null;
        }
        $count = unpack($u16, $tiff, $offset)[1];
        if ($offset + 2 + $count * 12 + 4 > $len) {
            return null;
        }
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $p = $offset + 2 + $i * 12;
            $tags[unpack($u16, $tiff, $p)[1]] = [
                'type' => unpack($u16, $tiff, $p + 2)[1],
                'count' => unpack($u32, $tiff, $p + 4)[1],
                'value' => substr($tiff, $p + 8, 4),
            ];
        }
        $next = unpack($u32, $tiff, $offset + 2 + $count * 12)[1];

        return ['tags' => $tags, 'hasNext' => $next !== 0, 'order' => $order];
    }

    /**
     * Orientation value 1-8 from a TIFF block, or null when missing or invalid.
     */
    public static function orientation(string $tiff): ?int
    {
        $ifd = self::readIfd0($tiff);
        $tag = $ifd['tags'][self::TAG_ORIENTATION] ?? null;
        if ($tag === null || $tag['type'] !== 3 || $tag['count'] !== 1) {
            return null;
        }
        $value = unpack($ifd['order'] === 'II' ? 'v' : 'n', $tag['value'])[1];

        return $value >= 1 && $value <= 8 ? $value : null;
    }

    /**
     * True when the block holds nothing but an Orientation tag, which is what
     * the cleaners write themselves.
     */
    public static function isOrientationOnly(string $tiff): bool
    {
        $ifd = self::readIfd0($tiff);

        return $ifd !== null
            && !$ifd['hasNext']
            && array_keys($ifd['tags']) === [self::TAG_ORIENTATION]
            && self::orientation($tiff) !== null;
    }

    /**
     * A big-endian TIFF block with a single IFD holding only Orientation.
     */
    public static function buildOrientationOnly(int $orientation): string
    {
        return "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('nnN', self::TAG_ORIENTATION, 3, 1) . pack('n', $orientation) . "\x00\x00"
            . pack('N', 0);
    }
}
