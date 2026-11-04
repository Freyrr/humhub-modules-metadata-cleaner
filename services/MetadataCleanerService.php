<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanerInterface;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\cleaners\FlacCleaner;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\GifCleaner;
use humhub\modules\metadataCleaner\cleaners\HeifCleaner;
use humhub\modules\metadataCleaner\cleaners\JpegCleaner;
use humhub\modules\metadataCleaner\cleaners\MatroskaCleaner;
use humhub\modules\metadataCleaner\cleaners\Mp3Cleaner;
use humhub\modules\metadataCleaner\cleaners\OfficeCleaner;
use humhub\modules\metadataCleaner\cleaners\OggCleaner;
use humhub\modules\metadataCleaner\cleaners\PdfCleaner;
use humhub\modules\metadataCleaner\cleaners\PngCleaner;
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
            FormatDetector::TYPE_DNG => new TiffCleaner(dng: true),
            FormatDetector::FORMAT_PDF => new PdfCleaner(),
            FormatDetector::FORMAT_OFFICE => new OfficeCleaner(),
            FormatDetector::FORMAT_SVG => new SvgCleaner(),
            default => throw new CleanerException(sprintf('No cleaner for format "%s".', $type)),
        };
    }
}
