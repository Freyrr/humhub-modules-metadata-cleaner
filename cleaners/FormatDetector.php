<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Detects the file format from its first bytes. The file name and the MIME
 * type sent by the browser are never trusted.
 */
final class FormatDetector
{
    public const FORMAT_JPEG = 'jpeg';
    public const FORMAT_PNG = 'png';
    public const FORMAT_WEBP = 'webp';
    public const FORMAT_GIF = 'gif';
    public const FORMAT_HEIF = 'heif';
    public const FORMAT_VIDEO = 'video';
    public const FORMAT_TIFF = 'tiff';
    public const FORMAT_AUDIO = 'audio';

    /** Format groups, as switched on and off in the settings. */
    public const ALL_FORMATS = [
        self::FORMAT_JPEG, self::FORMAT_PNG, self::FORMAT_WEBP, self::FORMAT_GIF,
        self::FORMAT_HEIF, self::FORMAT_VIDEO, self::FORMAT_AUDIO, self::FORMAT_TIFF,
    ];

    /** Container types inside the groups, used to pick the cleaner. */
    public const TYPE_MP4 = 'mp4';
    public const TYPE_M4A = 'm4a';
    public const TYPE_MATROSKA = 'matroska';
    public const TYPE_MP3 = 'mp3';
    public const TYPE_FLAC = 'flac';
    public const TYPE_OGG = 'ogg';

    private const TYPE_GROUPS = [
        self::TYPE_MP4 => self::FORMAT_VIDEO,
        self::TYPE_MATROSKA => self::FORMAT_VIDEO,
        self::TYPE_M4A => self::FORMAT_AUDIO,
        self::TYPE_MP3 => self::FORMAT_AUDIO,
        self::TYPE_FLAC => self::FORMAT_AUDIO,
        self::TYPE_OGG => self::FORMAT_AUDIO,
    ];

    /** ftyp major brands of audio-only MPEG-4 files (M4A, M4B audiobooks...). */
    private const AUDIO_BRANDS = ['M4A ', 'M4B ', 'M4P ', 'F4A ', 'F4B '];

    /** ftyp brands of HEIF/HEIC/AVIF still images. */
    private const HEIF_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'mif2', 'msf1', 'avif', 'avis'];

    /** ftyp major brands of MP4, MOV and 3GP files. */
    private const VIDEO_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4VP', 'qt  ', '3gp4', '3gp5', '3gp6', '3gp7', '3gs7', '3g2a', '3g2b', '3g2c', 'dash', 'MSNV', 'f4v '];

    /** Top-level boxes that can start a QuickTime file without ftyp. */
    private const QUICKTIME_START_BOXES = ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'];

    /**
     * Returns the format group of a file (one of ALL_FORMATS), or null.
     */
    public static function detect(string $path): ?string
    {
        return self::group(self::detectType($path));
    }

    /**
     * Returns the detailed type of a file: a FORMAT_* or TYPE_* value, or null.
     */
    public static function detectType(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        $head = (string)fread($fh, 64);
        fclose($fh);

        return self::detectTypeFromBytes($head);
    }

    public static function group(?string $type): ?string
    {
        return $type === null ? null : (self::TYPE_GROUPS[$type] ?? $type);
    }

    public static function detectFromBytes(string $head): ?string
    {
        return self::group(self::detectTypeFromBytes($head));
    }

    public static function detectTypeFromBytes(string $head): ?string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return self::FORMAT_JPEG;
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return self::FORMAT_PNG;
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return self::FORMAT_GIF;
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return self::FORMAT_WEBP;
        }
        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
            return self::FORMAT_TIFF;
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return self::detectIsoBmff($head);
        }
        if (strlen($head) >= 8 && in_array(substr($head, 4, 4), self::QUICKTIME_START_BOXES, true)) {
            return self::TYPE_MP4;
        }
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return self::TYPE_MATROSKA;
        }
        if (str_starts_with($head, 'fLaC')) {
            return self::TYPE_FLAC;
        }
        if (str_starts_with($head, 'OggS')) {
            return self::TYPE_OGG;
        }
        // ID3 tag, or an MPEG audio / ADTS AAC frame sync (11 bits set).
        if (str_starts_with($head, 'ID3') || (strlen($head) >= 2 && $head[0] === "\xFF" && (ord($head[1]) & 0xE0) === 0xE0)) {
            return self::TYPE_MP3;
        }
        return null;
    }

    private static function detectIsoBmff(string $head): string
    {
        $size = unpack('N', substr($head, 0, 4))[1];
        $brands = [substr($head, 8, 4)];
        $end = min(strlen($head), max(16, $size));
        for ($i = 16; $i + 4 <= $end; $i += 4) {
            $brands[] = substr($head, $i, 4);
        }
        // The major brand decides. Compatible brands only count when the major
        // brand is not a known video brand.
        if (in_array($brands[0], self::HEIF_BRANDS, true)) {
            return self::FORMAT_HEIF;
        }
        if (!in_array($brands[0], self::VIDEO_BRANDS, true) && array_intersect(self::HEIF_BRANDS, $brands)) {
            return self::FORMAT_HEIF;
        }
        if (in_array($brands[0], self::AUDIO_BRANDS, true)) {
            return self::TYPE_M4A;
        }
        return self::TYPE_MP4;
    }
}
