<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\readers\ExifReader;

/**
 * Removes metadata from TIFF files in plain PHP, without touching the image
 * data.
 *
 * Every image directory (IFD) is rewritten at the end of the file with only
 * the tags needed to decode the image. The old directories, the EXIF, GPS and
 * Interoperability directories and the values of the removed tags are then
 * overwritten with zeros. Image strips and tiles stay where they are.
 * BigTIFF is not supported.
 */
final class TiffCleaner implements CleanerInterface
{
    private const TAG_ORIENTATION = 0x0112;
    private const TAG_SUB_IFDS = 0x014A;
    private const TAG_EXIF_IFD = 0x8769;
    private const TAG_GPS_IFD = 0x8825;
    private const TAG_INTEROP_IFD = 0xA005;

    /** Tags needed to decode and show the image. */
    private const KEEP = [
        0x00FE, 0x00FF, 0x0100, 0x0101, 0x0102, 0x0103, 0x0106, 0x0107, 0x0108, 0x0109, 0x010A, 0x0111,
        0x0115, 0x0116, 0x0117, 0x0118, 0x0119, 0x011A, 0x011B, 0x011C, 0x0122, 0x0123, 0x0124, 0x0125,
        0x0128, 0x0129, 0x012D, 0x013D, 0x013E, 0x013F, 0x0140, 0x0141, 0x0142, 0x0143, 0x0144, 0x0145, 0x014C,
        0x014D, 0x014E, 0x0150, 0x0151, 0x0152, 0x0153, 0x0154, 0x0155, 0x0156, 0x015B, 0x0200, 0x0201,
        0x0202, 0x0203, 0x0205, 0x0206, 0x0207, 0x0208, 0x0209, 0x0211, 0x0212, 0x0213, 0x0214, 0x8773,
    ];

    /** Pairs of (offsets tag, byte counts tag) that point to image data. */
    private const DATA_TAGS = [[0x0111, 0x0117], [0x0144, 0x0145], [0x0201, 0x0202]];

    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];

    private const MAX_FILE_BYTES = 536870912;
    private const MAX_IFDS = 1000;

    private string $data = '';
    private string $u16 = 'n';
    private string $u32 = 'N';
    /** @var list<array{0: int, 1: int}> */
    private array $zero = [];
    /** @var list<array{0: int, 1: int}> */
    private array $protected = [];
    /** @var array<int, true> */
    private array $seen = [];
    /** @var array<string, string> */
    private array $fields = [];
    private bool $keepOrientation = true;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        if ((int)@filesize($path) > self::MAX_FILE_BYTES) {
            throw new CleanerException('TIFF file too large.');
        }
        $this->data = FileIo::read($path);
        $this->zero = $this->protected = $this->seen = $this->fields = [];
        $this->keepOrientation = $options->keepOrientation;

        $order = substr($this->data, 0, 2);
        [$this->u16, $this->u32] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        if (strlen($this->data) < 8 || $this->u16(2) !== 42) {
            throw new CleanerException('Not a supported TIFF file (BigTIFF is not supported).');
        }

        // Read every image directory of the main chain.
        $ifds = [];
        $offset = $this->u32(4);
        while ($offset !== 0) {
            if (count($ifds) >= self::MAX_IFDS) {
                throw new CleanerException('Too many TIFF directories.');
            }
            $ifds[] = $this->readImageIfd($offset, 'IFD' . count($ifds));
            $offset = end($ifds)['next'];
        }
        if ($ifds === []) {
            throw new CleanerException('TIFF file without images.');
        }

        $changed = false;
        foreach ($ifds as $ifd) {
            $changed = $changed || $ifd['dropped'] || $ifd['subChanged'];
        }
        if (!$changed) {
            return new CleanResult(FormatDetector::FORMAT_TIFF, false);
        }

        // Write the new directories at the end of the file.
        $out = $this->data;
        $newOffsets = [];
        foreach ($ifds as $i => $ifd) {
            $newOffsets[$i] = $this->appendIfd($out, $ifd);
        }
        foreach ($ifds as $i => $ifd) {
            $next = $newOffsets[$i + 1] ?? 0;
            $out = substr_replace($out, pack($this->u32, $next), $newOffsets[$i] + 2 + 12 * count($ifd['entries']), 4);
        }
        $out = substr_replace($out, pack($this->u32, $newOffsets[0]), 4, 4);

        foreach ($this->zero as [$start, $length]) {
            if ($length > 0 && !$this->overlapsProtected($start, $length)) {
                $out = substr_replace($out, str_repeat("\x00", $length), $start, $length);
            }
        }

        FileIo::replace($path, $out);

        return new CleanResult(FormatDetector::FORMAT_TIFF, true, [
            new MetadataBlock(MetadataBlock::TYPE_EXIF, 'TIFF', 0, null, $options->collectPayloads ? $this->fields : []),
        ]);
    }

    /**
     * Reads an image directory, decides which tags stay, and records what
     * must be zeroed. SubIFDs (more images) are cleaned the same way.
     */
    private function readImageIfd(int $offset, string $group): array
    {
        $entries = $this->readEntries($offset);
        $this->zero[] = [$offset, 2 + 12 * count($entries['list']) + 4];
        $kept = [];
        $dropped = false;
        $subIfds = [];
        $subChanged = false;

        foreach ($entries['list'] as $entry) {
            $tag = $entry['tag'];
            if ($tag === self::TAG_SUB_IFDS) {
                foreach ($this->values($entry) as $subOffset) {
                    $sub = $this->readImageIfd($subOffset, $group . ':SubIFD');
                    $subIfds[] = $sub;
                    $subChanged = $subChanged || $sub['dropped'] || $sub['subChanged'];
                }
                $kept[] = $entry;
                continue;
            }
            if (in_array($tag, self::KEEP, true) || ($tag === self::TAG_ORIENTATION && $this->keepOrientation)) {
                $kept[] = $entry;
                if ($entry['outOfLine']) {
                    $this->protected[] = [$entry['dataOffset'], $entry['dataLength']];
                }
                continue;
            }
            $dropped = true;
            $this->fields['EXIF:' . $group . ':' . ExifReader::tagName('IFD', $tag)] = '';
            if (in_array($tag, [self::TAG_EXIF_IFD, self::TAG_GPS_IFD, self::TAG_INTEROP_IFD], true)) {
                foreach ($this->values($entry) as $subOffset) {
                    $this->dropMetadataIfd($subOffset, $tag === self::TAG_GPS_IFD ? 'GPS' : ($tag === self::TAG_EXIF_IFD ? 'ExifIFD' : 'InteropIFD'));
                }
            }
            if ($entry['outOfLine']) {
                $this->zero[] = [$entry['dataOffset'], $entry['dataLength']];
            }
        }

        // Image data must never be zeroed.
        $byTag = array_column($kept, null, 'tag');
        foreach (self::DATA_TAGS as [$offsetsTag, $countsTag]) {
            if (isset($byTag[$offsetsTag], $byTag[$countsTag])) {
                $offsets = $this->values($byTag[$offsetsTag]);
                $counts = $this->values($byTag[$countsTag]);
                foreach ($offsets as $i => $start) {
                    $this->protected[] = [$start, $counts[$i] ?? 0];
                }
            }
        }

        return ['entries' => $kept, 'next' => $entries['next'], 'dropped' => $dropped, 'subIfds' => $subIfds, 'subChanged' => $subChanged];
    }

    /**
     * Zeroes an EXIF, GPS or Interoperability directory and all its values.
     */
    private function dropMetadataIfd(int $offset, string $group): void
    {
        if (isset($this->seen[$offset])) {
            return;
        }
        $entries = $this->readEntries($offset);
        $this->zero[] = [$offset, 2 + 12 * count($entries['list']) + 4];
        foreach ($entries['list'] as $entry) {
            $this->fields['EXIF:' . $group . ':' . ExifReader::tagName($group, $entry['tag'])] = '';
            if ($entry['tag'] === self::TAG_INTEROP_IFD) {
                foreach ($this->values($entry) as $sub) {
                    $this->dropMetadataIfd($sub, 'InteropIFD');
                }
            }
            if ($entry['outOfLine']) {
                $this->zero[] = [$entry['dataOffset'], $entry['dataLength']];
            }
        }
    }

    /**
     * @return array{list: list<array>, next: int}
     */
    private function readEntries(int $offset): array
    {
        if (isset($this->seen[$offset]) || $offset < 8 || $offset + 2 > strlen($this->data)) {
            throw new CleanerException(sprintf('Invalid TIFF directory offset %d.', $offset));
        }
        $this->seen[$offset] = true;
        $count = $this->u16($offset);
        $end = $offset + 2 + 12 * $count;
        if ($end + 4 > strlen($this->data)) {
            throw new CleanerException('Truncated TIFF directory.');
        }
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $p = $offset + 2 + 12 * $i;
            $type = $this->u16($p + 2);
            $n = $this->u32($p + 4);
            $length = (self::TYPE_SIZES[$type] ?? 1) * $n;
            $outOfLine = $length > 4;
            $dataOffset = $outOfLine ? $this->u32($p + 8) : $p + 8;
            if ($outOfLine && $dataOffset + $length > strlen($this->data)) {
                throw new CleanerException('TIFF value outside of the file.');
            }
            $list[] = [
                'tag' => $this->u16($p),
                'type' => $type,
                'count' => $n,
                'raw' => substr($this->data, $p, 12),
                'outOfLine' => $outOfLine,
                'dataOffset' => $dataOffset,
                'dataLength' => $length,
            ];
        }

        return ['list' => $list, 'next' => $this->u32($end)];
    }

    /**
     * Integer values of a SHORT, LONG or IFD entry.
     *
     * @return int[]
     */
    private function values(array $entry): array
    {
        $size = match ($entry['type']) {
            3 => 2,
            4, 13 => 4,
            default => throw new CleanerException('Unexpected TIFF value type.'),
        };
        $raw = substr($this->data, $entry['dataOffset'], $entry['dataLength']);

        return array_values(unpack(($size === 2 ? $this->u16 : $this->u32) . '*', $raw) ?: []);
    }

    /**
     * Appends a directory (and its SubIFDs) at the end of $out.
     *
     * @return int offset of the new directory
     */
    private function appendIfd(string &$out, array $ifd): int
    {
        $subOffsets = [];
        foreach ($ifd['subIfds'] as $sub) {
            $subOffsets[] = $this->appendIfd($out, $sub);
        }
        if (strlen($out) % 2 === 1) {
            $out .= "\x00";
        }

        $entries = [];
        foreach ($ifd['entries'] as $entry) {
            $raw = $entry['raw'];
            if ($entry['tag'] === self::TAG_SUB_IFDS && $subOffsets !== []) {
                $values = implode('', array_map(fn($o) => pack($this->u32, $o), $subOffsets));
                if (count($subOffsets) === 1) {
                    $raw = pack($this->u16 . $this->u16 . $this->u32, self::TAG_SUB_IFDS, 4, 1) . $values;
                } else {
                    $arrayOffset = strlen($out);
                    $out .= $values;
                    $raw = pack($this->u16 . $this->u16 . $this->u32 . $this->u32, self::TAG_SUB_IFDS, 4, count($subOffsets), $arrayOffset);
                }
            }
            $entries[] = $raw;
        }
        if (strlen($out) % 2 === 1) {
            $out .= "\x00";
        }
        $offset = strlen($out);
        $out .= pack($this->u16, count($entries)) . implode('', $entries) . pack($this->u32, 0);

        return $offset;
    }

    private function overlapsProtected(int $start, int $length): bool
    {
        foreach ($this->protected as [$pStart, $pLength]) {
            if ($start < $pStart + $pLength && $pStart < $start + $length) {
                return true;
            }
        }

        return false;
    }

    private function u16(int $p): int
    {
        return unpack($this->u16, $this->data, $p)[1];
    }

    private function u32(int $p): int
    {
        return unpack($this->u32, $this->data, $p)[1];
    }
}
