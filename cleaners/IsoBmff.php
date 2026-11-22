<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Reads the box structure of ISO base media files (MP4, MOV, 3GP, HEIF).
 */
final class IsoBmff
{
    /** UUID of XMP boxes. */
    public const XMP_UUID = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";

    /** UUID of C2PA content-credential manifests in BMFF files (JUMBF store). */
    public const C2PA_UUID = "\xD8\xFE\xC3\xD6\x1B\x0E\x48\x3C\x92\x97\x58\x28\x87\x7E\xC4\x81";

    /**
     * Lists the boxes in $data between $start and $end.
     *
     * @return list<array{type: string, start: int, size: int, header: int}>
     *     start = offset of the box, size = full size, header = header length
     * @throws CleanerException on a malformed box
     */
    public static function boxes(string $data, int $start, int $end): array
    {
        $boxes = [];
        $pos = $start;
        while ($pos < $end) {
            if ($pos + 8 > $end) {
                // QuickTime allows a 4-byte zero terminator at the end of some containers.
                if ($end - $pos === 4 && substr($data, $pos, 4) === "\x00\x00\x00\x00") {
                    break;
                }
                throw new CleanerException(sprintf('Truncated box header at offset %d.', $pos));
            }
            $size = unpack('N', $data, $pos)[1];
            $type = substr($data, $pos + 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($pos + 16 > $end) {
                    throw new CleanerException(sprintf('Truncated box header at offset %d.', $pos));
                }
                $size = unpack('J', $data, $pos + 8)[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }
            if ($size < $header || $pos + $size > $end) {
                throw new CleanerException(sprintf('Invalid size of box "%s" at offset %d.', self::printable($type), $pos));
            }
            if ($type === 'uuid') {
                $header += 16;
                if ($size < $header) {
                    throw new CleanerException(sprintf('Invalid uuid box at offset %d.', $pos));
                }
            }
            $boxes[] = ['type' => $type, 'start' => $pos, 'size' => $size, 'header' => $header];
            $pos += $size;
        }

        return $boxes;
    }

    /**
     * Reads the top-level box headers of a file without loading it.
     *
     * @param resource $fh
     * @return list<array{type: string, start: int, size: int, header: int, uuid: string}>
     */
    public static function topLevelBoxes($fh, int $fileSize): array
    {
        $boxes = [];
        $pos = 0;
        while ($pos < $fileSize) {
            if ($fileSize - $pos < 8) {
                if (self::readAt($fh, $pos, $fileSize - $pos) === str_repeat("\x00", $fileSize - $pos)) {
                    break;
                }
                throw new CleanerException(sprintf('Truncated box header at offset %d.', $pos));
            }
            $head = self::readAt($fh, $pos, min(32, $fileSize - $pos));
            $size = unpack('N', $head)[1];
            $type = substr($head, 4, 4);
            $header = 8;
            if ($size === 1) {
                if (strlen($head) < 16) {
                    throw new CleanerException('Truncated box header.');
                }
                $size = unpack('J', $head, 8)[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $fileSize - $pos;
            }
            if ($size < $header || $pos + $size > $fileSize) {
                throw new CleanerException(sprintf('Invalid size of box "%s" at offset %d.', self::printable($type), $pos));
            }
            $uuid = '';
            if ($type === 'uuid') {
                $uuid = (string)substr($head, $header, 16);
                $header += 16;
            }
            $boxes[] = ['type' => $type, 'start' => $pos, 'size' => $size, 'header' => $header, 'uuid' => $uuid];
            $pos += $size;
        }

        return $boxes;
    }

    /**
     * @param resource $fh
     */
    public static function readAt($fh, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (fseek($fh, $offset) !== 0) {
            throw new CleanerException('Cannot seek in file.');
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($fh, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new CleanerException('Unexpected end of file.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * A "free" box of exactly $size bytes, filled with zeros.
     */
    public static function freeBox(int $size, bool $large = false): string
    {
        if ($large || $size > 0xFFFFFFFF) {
            return pack('N', 1) . 'free' . pack('J', $size) . str_repeat("\x00", $size - 16);
        }

        return pack('N', $size) . 'free' . str_repeat("\x00", $size - 8);
    }

    public static function printable(string $type): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', $type) ?? '????';
    }
}
