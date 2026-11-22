<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Overwrites byte ranges of a file without changing its size.
 *
 * The changes are written to a copy, which then replaces the original, so
 * the original is never left half-changed.
 */
final class FilePatcher
{
    /**
     * @param array<int, string|int> $patches offset => bytes to write, or
     *     offset => number of zero bytes to write
     */
    public static function apply(string $path, array $patches): void
    {
        $tmp = @tempnam(dirname($path), '.mdc');
        if ($tmp === false) {
            throw new CleanerException('Cannot create temporary file.');
        }
        try {
            if (!@copy($path, $tmp)) {
                throw new CleanerException('Cannot copy file.');
            }
            $size = filesize($tmp);
            $fh = @fopen($tmp, 'r+b');
            if ($fh === false) {
                throw new CleanerException('Cannot open temporary file.');
            }
            try {
                ksort($patches);
                foreach ($patches as $offset => $patch) {
                    $length = is_int($patch) ? $patch : strlen($patch);
                    if ($offset < 0 || $offset + $length > $size) {
                        throw new CleanerException('Patch outside of the file.');
                    }
                    if (fseek($fh, $offset) !== 0) {
                        throw new CleanerException('Cannot seek in file.');
                    }
                    is_int($patch) ? self::writeZeros($fh, $patch) : self::write($fh, $patch);
                }
                fflush($fh);
            } finally {
                fclose($fh);
            }
            $perms = @fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
            if (!@rename($tmp, $path)) {
                throw new CleanerException('Cannot replace file.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        clearstatcache(true, $path);
    }

    /**
     * @param resource $fh
     */
    private static function write($fh, string $data): void
    {
        if (fwrite($fh, $data) !== strlen($data)) {
            throw new CleanerException('Cannot write file.');
        }
    }

    /**
     * @param resource $fh
     */
    private static function writeZeros($fh, int $count): void
    {
        $block = str_repeat("\x00", 65536);
        while ($count > 0) {
            $n = min($count, 65536);
            self::write($fh, $n === 65536 ? $block : substr($block, 0, $n));
            $count -= $n;
        }
    }
}
