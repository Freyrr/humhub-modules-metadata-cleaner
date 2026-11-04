<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner;

use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use Yii;
use yii\helpers\Url;

/**
 * Metadata Cleaner module.
 *
 * Settings (stored with the module settings):
 * - logMode: one of the LOG_* constants
 * - logRetentionDays: days a log row is kept
 * - keepOrientation: keep the EXIF Orientation tag
 * - tiffPolicy: one of the TIFF_* constants
 * - onError: one of the ON_ERROR_* constants
 */
class Module extends \humhub\components\Module
{
    public const LOG_OFF = 'off';
    public const LOG_FIELDS = 'fields';
    public const LOG_FIELDS_AND_VALUES = 'values';

    public const TIFF_REJECT = 'reject';
    public const TIFF_IMAGEMAGICK = 'imagemagick';

    /** On any error the file is passed to HumHub unchanged. */
    public const ON_ERROR_PASS = 'pass';
    /** On any error the file is rejected. */
    public const ON_ERROR_REJECT = 'reject';

    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * @inheritdoc
     */
    public $resourcesPath = 'assets';

    public function getLogMode(): string
    {
        $mode = $this->settings->get('logMode', self::LOG_FIELDS);

        return in_array($mode, [self::LOG_OFF, self::LOG_FIELDS, self::LOG_FIELDS_AND_VALUES], true)
            ? $mode : self::LOG_FIELDS;
    }

    public function getLogRetentionDays(): int
    {
        return max(1, (int)$this->settings->get('logRetentionDays', self::DEFAULT_RETENTION_DAYS));
    }

    public function getKeepOrientation(): bool
    {
        return (bool)$this->settings->get('keepOrientation', true);
    }

    public function getTiffPolicy(): string
    {
        return $this->settings->get('tiffPolicy', self::TIFF_REJECT) === self::TIFF_IMAGEMAGICK
            ? self::TIFF_IMAGEMAGICK : self::TIFF_REJECT;
    }

    public function getOnError(): string
    {
        return $this->settings->get('onError', self::ON_ERROR_PASS) === self::ON_ERROR_REJECT
            ? self::ON_ERROR_REJECT : self::ON_ERROR_PASS;
    }

    public function getCleanerService(): MetadataCleanerService
    {
        return new MetadataCleanerService(
            new CleanOptions(
                keepOrientation: $this->getKeepOrientation(),
                collectPayloads: $this->getLogMode() !== self::LOG_OFF,
            ),
            $this->getTiffPolicy() === self::TIFF_IMAGEMAGICK,
        );
    }

    /**
     * @inheritdoc
     */
    public function getDescription()
    {
        return Yii::t('MetadataCleanerModule.base', 'Removes EXIF, XMP and IPTC metadata (GPS included) from uploaded images and videos.');
    }

    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/metadata-cleaner/admin']);
    }
}
