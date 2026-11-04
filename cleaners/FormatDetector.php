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

    /** ftyp brands of HEIF/HEIC/AVIF still images. */
    private const HEIF_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'mif2', 'msf1', 'avif', 'avis'];

    /** ftyp major brands of MP4, MOV and 3GP files. */
    private const VIDEO_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4VP', 'M4A ', 'qt  ', '3gp4', '3gp5', '3gp6', '3gp7', '3gs7', '3g2a', '3g2b', '3g2c', 'dash', 'MSNV', 'f4v '];

    /** Top-level boxes that can start a QuickTime file without ftyp. */
    private const QUICKTIME_START_BOXES = ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'];

    public static function detect(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        $head = (string)fread($fh, 64);
        fclose($fh);

        return self::detectFromBytes($head);
    }

    public static function detectFromBytes(string $head): ?string
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
            return self::FORMAT_VIDEO;
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
        return self::FORMAT_VIDEO;
    }
}
