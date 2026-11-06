<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\metadataCleaner\cleaners\AiffCleaner;
use humhub\modules\metadataCleaner\cleaners\AsfCleaner;
use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanerInterface;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\cleaners\FlacCleaner;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\GifCleaner;
use humhub\modules\metadataCleaner\cleaners\HeifCleaner;
use humhub\modules\metadataCleaner\cleaners\Jp2Cleaner;
use humhub\modules\metadataCleaner\cleaners\JpegCleaner;
use humhub\modules\metadataCleaner\cleaners\JxlCleaner;
use humhub\modules\metadataCleaner\cleaners\MatroskaCleaner;
use humhub\modules\metadataCleaner\cleaners\Mp3Cleaner;
use humhub\modules\metadataCleaner\cleaners\OfficeCleaner;
use humhub\modules\metadataCleaner\cleaners\OggCleaner;
use humhub\modules\metadataCleaner\cleaners\PdfCleaner;
use humhub\modules\metadataCleaner\cleaners\PngCleaner;
use humhub\modules\metadataCleaner\cleaners\RiffCleaner;
use humhub\modules\metadataCleaner\cleaners\SvgCleaner;
use humhub\modules\metadataCleaner\cleaners\TiffCleaner;
use humhub\modules\metadataCleaner\cleaners\VideoCleaner;
use humhub\modules\metadataCleaner\cleaners\WebpCleaner;

/**
 * Picks the cleaner for a file and runs it. Has no HumHub dependency, so it
 * can be used and tested on plain files.
 */
class MetadataCleanerService
{
    /**
     * Detected types whose cleaners load the whole file into memory
     * (FileIo::read). Streaming cleaners are not listed.
     */
    private const MEMORY_BOUND_TYPES = [
        FormatDetector::FORMAT_JPEG,
        FormatDetector::FORMAT_PNG,
        FormatDetector::FORMAT_WEBP,
        FormatDetector::FORMAT_GIF,
        FormatDetector::FORMAT_TIFF,
        FormatDetector::TYPE_DNG,
        FormatDetector::TYPE_RAW,
        FormatDetector::TYPE_WAV,
        FormatDetector::TYPE_AVI,
        FormatDetector::TYPE_AIFF,
        FormatDetector::FORMAT_PDF,
        FormatDetector::FORMAT_SVG,
    ];

    /**
     * @param string[] $formats formats to clean; files of other formats are left as they are
     */
    public function __construct(
        private readonly CleanOptions $options = new CleanOptions(),
        private readonly array $formats = FormatDetector::ALL_FORMATS,
    ) {
    }

    /**
     * Cleans the file in place.
     *
     * Returns CleanResult::notHandled() for formats that carry no image or
     * video metadata this module knows about (documents, archives, audio...).
     *
     * @throws CleanerException when the file must be rejected
     */
    public function cleanPath(string $path): CleanResult
    {
        $type = FormatDetector::detectType($path);
        $format = FormatDetector::group($type);
        if ($format === null || !in_array($format, $this->formats, true)) {
            return CleanResult::notHandled();
        }
        if (in_array($type, self::MEMORY_BOUND_TYPES, true)) {
            $size = @filesize($path);
            if ($size !== false && $size > $this->options->maxMemoryFileBytes) {
                throw new CleanerException(sprintf(
                    'File too large to clean safely (%d bytes, limit %d).',
                    $size,
                    $this->options->maxMemoryFileBytes,
                ));
            }
        }

        return $this->getCleaner($type)->clean($path, $this->options);
    }

    /**
     * Cleaner for a format detected by FormatDetector.
     *
     * @param string $type a FormatDetector FORMAT_* or TYPE_* value
     */
    public function getCleaner(string $type): CleanerInterface
    {
        return match ($type) {
            FormatDetector::FORMAT_JPEG => new JpegCleaner(),
            FormatDetector::FORMAT_PNG => new PngCleaner(),
            FormatDetector::FORMAT_WEBP => new WebpCleaner(),
            FormatDetector::FORMAT_GIF => new GifCleaner(),
            FormatDetector::FORMAT_HEIF => new HeifCleaner(),
            FormatDetector::FORMAT_VIDEO, FormatDetector::TYPE_MP4 => new VideoCleaner(),
            FormatDetector::TYPE_M4A => new VideoCleaner(FormatDetector::FORMAT_AUDIO),
            FormatDetector::TYPE_MATROSKA => new MatroskaCleaner(),
            FormatDetector::TYPE_MP3 => new Mp3Cleaner(),
            FormatDetector::TYPE_FLAC => new FlacCleaner(),
            FormatDetector::TYPE_OGG => new OggCleaner(),
            FormatDetector::FORMAT_TIFF => new TiffCleaner(),
            FormatDetector::TYPE_DNG, FormatDetector::TYPE_RAW => new TiffCleaner(dng: true),
            FormatDetector::TYPE_WAV, FormatDetector::TYPE_AVI => new RiffCleaner(),
            FormatDetector::TYPE_ASF => new AsfCleaner(),
            FormatDetector::TYPE_AIFF => new AiffCleaner(),
            // PSD/PSB is detected but intentionally not cleaned for now:
            // FormatDetector::FORMAT_PSD is not in ALL_FORMATS, so cleanPath()
            // returns notHandled before a cleaner is picked.
            FormatDetector::TYPE_JP2 => new Jp2Cleaner(),
            FormatDetector::TYPE_JXL => new JxlCleaner(),
            FormatDetector::FORMAT_PDF => new PdfCleaner(),
            FormatDetector::FORMAT_OFFICE => new OfficeCleaner(),
            FormatDetector::FORMAT_SVG => new SvgCleaner(),
            default => throw new CleanerException(sprintf('No cleaner for format "%s".', $type)),
        };
    }
}
