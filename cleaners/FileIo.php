<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Small file helpers shared by the cleaners.
 */
final class FileIo
{
    public static function read(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new CleanerException('Cannot read file.');
        }
        return $data;
    }

    /**
     * Replaces the file content atomically: a temporary file is written next to
     * the original and then renamed over it, so a crash never leaves half a file.
     */
    public static function replace(string $path, string $data): void
    {
        $tmp = @tempnam(dirname($path), '.mdc');
        if ($tmp === false) {
            throw new CleanerException('Cannot create temporary file.');
        }
        try {
            if (@file_put_contents($tmp, $data) !== strlen($data)) {
                throw new CleanerException('Cannot write temporary file.');
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
     * Replaces a file with the given byte ranges of itself, in order, without
     * loading it in memory. Used to cut tags out of large files.
     *
     * @param list<array{0: int, 1: int}> $ranges offset and length of each kept range
     */
    public static function keepRanges(string $path, array $ranges): void
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new CleanerException('Cannot open file.');
        }
        try {
            $tmp = self::writeTemp($path, static function ($out) use ($in, $ranges): void {
                foreach ($ranges as [$offset, $length]) {
                    self::copyRange($in, $out, $offset, $length);
                }
            });
        } finally {
            fclose($in);
        }
        self::commit($tmp, $path);
    }

    /**
     * Writes a temporary file next to $path through $write, which receives
     * the open output handle. The original is not touched: pass the returned
     * path to commit() to replace it, once every handle on it is closed.
     *
     * @param callable(resource): void $write
     * @return string path of the temporary file
     */
    public static function writeTemp(string $path, callable $write): string
    {
        $tmp = @tempnam(dirname($path), '.mdc');
        if ($tmp === false) {
            throw new CleanerException('Cannot create temporary file.');
        }
        try {
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                throw new CleanerException('Cannot open temporary file.');
            }
            try {
                $write($out);
                if (!fflush($out)) {
                    throw new CleanerException('Cannot write temporary file.');
                }
            } finally {
                fclose($out);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return $tmp;
    }

    /**
     * Atomically replaces $path with the temporary file made by writeTemp().
     */
    public static function commit(string $tmp, string $path): void
    {
        try {
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
     * Copies $length bytes at $offset of $in to the current position of $out.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function copyRange($in, $out, int $offset, int $length): void
    {
        if ($length <= 0) {
            return;
        }
        // stream_copy_to_stream() does not seek for an offset of 0.
        if ($offset < 0 || fseek($in, $offset) !== 0 || stream_copy_to_stream($in, $out, $length) !== $length) {
            throw new CleanerException('Cannot copy file data.');
        }
    }

    /**
     * Writes all of $data to $out.
     *
     * @param resource $out
     */
    public static function write($out, string $data): void
    {
        if ($data !== '' && fwrite($out, $data) !== strlen($data)) {
            throw new CleanerException('Cannot write temporary file.');
        }
    }

    /**
     * Reads $length bytes at $offset, failing when the file is shorter.
     *
     * @param resource $fh
     */
    public static function readAt($fh, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        if ($offset < 0 || fseek($fh, $offset) !== 0) {
            throw new CleanerException(sprintf('Cannot seek to offset %d.', $offset));
        }
        $data = '';
        while (strlen($data) < $length && !feof($fh)) {
            $chunk = fread($fh, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (strlen($data) !== $length) {
            throw new CleanerException(sprintf('Unexpected end of file at offset %d.', $offset + strlen($data)));
        }

        return $data;
    }
}
