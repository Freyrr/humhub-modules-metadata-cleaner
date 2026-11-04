<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanerInterface;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\GifCleaner;
use humhub\modules\metadataCleaner\cleaners\HeifCleaner;
use humhub\modules\metadataCleaner\cleaners\JpegCleaner;
use humhub\modules\metadataCleaner\cleaners\PngCleaner;
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
        private readonly bool $allowTiff = false,
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
        $format = FormatDetector::detect($path);
        if ($format === null || !in_array($format, $this->formats, true)) {
            return CleanResult::notHandled();
        }

        return $this->getCleaner($format)->clean($path, $this->options);
    }

    /**
     * @throws CleanerException when the format is recognised but not allowed
     */
    public function getCleaner(string $format): CleanerInterface
    {
        return match ($format) {
            FormatDetector::FORMAT_JPEG => new JpegCleaner(),
            FormatDetector::FORMAT_PNG => new PngCleaner(),
            FormatDetector::FORMAT_WEBP => new WebpCleaner(),
            FormatDetector::FORMAT_GIF => new GifCleaner(),
            FormatDetector::FORMAT_HEIF => new HeifCleaner(),
            FormatDetector::FORMAT_VIDEO => new VideoCleaner(),
            FormatDetector::FORMAT_TIFF => $this->allowTiff && TiffCleaner::isAvailable()
                ? new TiffCleaner()
                : throw new CleanerException('TIFF files are not accepted.'),
        };
    }
}
