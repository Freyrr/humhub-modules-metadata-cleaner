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
    public const FORMAT_RAW = 'raw';
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_OFFICE = 'office';
    public const FORMAT_SVG = 'svg';
    public const FORMAT_PSD = 'psd';

    /** Format groups, as switched on and off in the settings. */
    public const ALL_FORMATS = [
        self::FORMAT_JPEG, self::FORMAT_PNG, self::FORMAT_WEBP, self::FORMAT_GIF,
        self::FORMAT_HEIF, self::FORMAT_VIDEO, self::FORMAT_AUDIO, self::FORMAT_TIFF, self::FORMAT_RAW,
        self::FORMAT_PDF, self::FORMAT_OFFICE, self::FORMAT_SVG,
    ];

    /** Bytes read at the start of a text file to find the <svg> root. */
    private const SVG_HEAD_BYTES = 4096;

    /** Container types inside the groups, used to pick the cleaner. */
    public const TYPE_MP4 = 'mp4';
    public const TYPE_M4A = 'm4a';
    public const TYPE_MATROSKA = 'matroska';
    public const TYPE_MP3 = 'mp3';
    public const TYPE_FLAC = 'flac';
    public const TYPE_OGG = 'ogg';
    public const TYPE_DNG = 'dng';
    public const TYPE_RAW = 'raw';
    public const TYPE_WAV = 'wav';
    public const TYPE_AIFF = 'aiff';
    public const TYPE_AVI = 'avi';
    public const TYPE_ASF = 'asf';
    public const TYPE_PSD = 'psd';
    public const TYPE_JP2 = 'jp2';
    public const TYPE_JXL = 'jxl';

    private const TYPE_GROUPS = [
        self::TYPE_MP4 => self::FORMAT_VIDEO,
        self::TYPE_MATROSKA => self::FORMAT_VIDEO,
        self::TYPE_AVI => self::FORMAT_VIDEO,
        self::TYPE_ASF => self::FORMAT_VIDEO,
        self::TYPE_M4A => self::FORMAT_AUDIO,
        self::TYPE_MP3 => self::FORMAT_AUDIO,
        self::TYPE_FLAC => self::FORMAT_AUDIO,
        self::TYPE_OGG => self::FORMAT_AUDIO,
        self::TYPE_WAV => self::FORMAT_AUDIO,
        self::TYPE_AIFF => self::FORMAT_AUDIO,
        self::TYPE_DNG => self::FORMAT_RAW,
        self::TYPE_RAW => self::FORMAT_RAW,
        self::TYPE_PSD => self::FORMAT_PSD,
        self::TYPE_JP2 => self::FORMAT_JPEG,
        self::TYPE_JXL => self::FORMAT_JPEG,
    ];

    private const TAG_DNG_VERSION = 0xC612;
    private const TAG_SUB_IFDS = 0x014A;
    private const TAG_MAKE = 0x010F;

    /** Largest first image directory read to tell TIFF, DNG and camera RAW apart. */
    private const MAX_IFD0_BYTES = 65536;

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
        try {
            $head = (string)fread($fh, 64);
            $type = self::detectTypeFromBytes($head);
            if ($type === self::FORMAT_TIFF) {
                $type = self::tiffType($fh, $head);
            } elseif ($type === null && self::looksLikeXml($head)) {
                fseek($fh, 0);
                $type = self::svgType((string)fread($fh, self::SVG_HEAD_BYTES));
            }
        } finally {
            fclose($fh);
        }

        return $type;
    }

    /**
     * Tells plain TIFF, DNG and other camera RAW files apart. Camera RAW
     * files (CR2, NEF, ARW...) also start like TIFF but need their private
     * tags to be decoded, so they are not handled (null).
     *
     * @param resource $fh
     */
    private static function tiffType($fh, string $head): ?string
    {
        if (substr($head, 8, 2) === 'CR') {
            return self::TYPE_RAW;
        }
        $u16 = $head[0] === 'I' ? 'v' : 'n';
        $u32 = $head[0] === 'I' ? 'V' : 'N';
        $offset = unpack($u32, $head, 4)[1];
        if ($offset < 8 || fseek($fh, $offset) !== 0) {
            return self::FORMAT_TIFF;
        }
        $count = unpack($u16, str_pad((string)fread($fh, 2), 2, "\x00"))[1];
        $entries = (string)fread($fh, min(self::MAX_IFD0_BYTES, $count * 12));
        $tags = [];
        for ($p = 0; $p + 12 <= strlen($entries); $p += 12) {
            $tags[unpack($u16, $entries, $p)[1]] = true;
        }
        if (isset($tags[self::TAG_DNG_VERSION])) {
            return self::TYPE_DNG;
        }
        if (isset($tags[self::TAG_SUB_IFDS]) && isset($tags[self::TAG_MAKE])) {
            return self::TYPE_RAW;
        }

        return self::FORMAT_TIFF;
    }

    public static function looksLikeXml(string $head): bool
    {
        return str_starts_with(ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head), '<');
    }

    /**
     * An XML file whose first element is <svg>.
     */
    public static function svgType(string $text): ?string
    {
        // Skip the declaration, comments, processing instructions and the doctype.
        $text = preg_replace('/<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>\[]*(\[.*?\])?\s*>/s', '', $text) ?? '';

        return preg_match('/^\s*<(?:[A-Za-z_][\w.-]*:)?svg[\s>\/]/', ltrim($text, "\xEF\xBB\xBF")) ? self::FORMAT_SVG : null;
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
        if (str_starts_with($head, 'RIFF') || str_starts_with($head, 'RF64')) {
            return match (substr($head, 8, 4)) {
                'WEBP' => self::FORMAT_WEBP,
                'WAVE' => self::TYPE_WAV,
                'AVI ' => self::TYPE_AVI,
                default => null,
            };
        }
        if (str_starts_with($head, 'FORM') && in_array(substr($head, 8, 4), ['AIFF', 'AIFC'], true)) {
            return self::TYPE_AIFF;
        }
        if (str_starts_with($head, "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C")) {
            return self::TYPE_ASF;
        }
        if (str_starts_with($head, '8BPS')) {
            return self::TYPE_PSD;
        }
        if (str_starts_with($head, "\x00\x00\x00\x0CjP  \r\n\x87\n")) {
            return self::TYPE_JP2;
        }
        if (str_starts_with($head, "\x00\x00\x00\x0CJXL \r\n\x87\n")) {
            return self::TYPE_JXL;
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
        if (str_starts_with($head, '%PDF-')) {
            return self::FORMAT_PDF;
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            return self::FORMAT_OFFICE;
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
