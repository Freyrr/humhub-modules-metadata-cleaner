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
}
