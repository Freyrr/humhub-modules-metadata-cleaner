<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner;

use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use Yii;
use yii\helpers\Url;

/**
 * Metadata Cleaner module.
 *
 * Settings (stored with the module settings):
 * - logMode: one of the LOG_* constants
 * - logRetentionDays: days a log row is kept
 * - statsRetentionMonths: months of statistics buckets are kept
 * - keepOrientation: keep the EXIF Orientation tag
 * - onError: one of the ON_ERROR_* constants
 * - formats: comma-separated FormatDetector::FORMAT_* values to clean (default: all)
 */
class Module extends \humhub\components\Module
{
    public const LOG_OFF = 'off';
    public const LOG_FIELDS = 'fields';
    public const LOG_FIELDS_AND_VALUES = 'values';

    /** On any error the file is passed to HumHub unchanged. */
    public const ON_ERROR_PASS = 'pass';
    /** On any error the file is rejected. */
    public const ON_ERROR_REJECT = 'reject';

    public const DEFAULT_RETENTION_DAYS = 30;
    public const DEFAULT_STATS_RETENTION_MONTHS = 3;

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

    public function getStatsRetentionMonths(): int
    {
        return max(1, (int)$this->settings->get('statsRetentionMonths', self::DEFAULT_STATS_RETENTION_MONTHS));
    }

    public function getKeepOrientation(): bool
    {
        return (bool)$this->settings->get('keepOrientation', true);
    }

    public function getOnError(): string
    {
        return $this->settings->get('onError', self::ON_ERROR_PASS) === self::ON_ERROR_REJECT
            ? self::ON_ERROR_REJECT : self::ON_ERROR_PASS;
    }

    /**
     * Formats the admin chose to clean. Files of other formats are left as they are.
     *
     * @return string[]
     */
    public function getEnabledFormats(): array
    {
        $value = $this->settings->get('formats');
        if ($value === null) {
            return FormatDetector::ALL_FORMATS;
        }

        return array_values(array_intersect(FormatDetector::ALL_FORMATS, explode(',', (string)$value)));
    }

    public function getCleanerService(): MetadataCleanerService
    {
        return new MetadataCleanerService(
            new CleanOptions(
                keepOrientation: $this->getKeepOrientation(),
                collectPayloads: $this->getLogMode() !== self::LOG_OFF,
                maxMemoryFileBytes: $this->getMaxMemoryFileBytes(),
            ),
            $this->getEnabledFormats(),
        );
    }

    /**
     * Largest file a memory-bound cleaner may handle. Capped by the
     * "Maximum upload file size" setting of the HumHub file module, so
     * the limit never rejects a file HumHub itself would not accept.
     */
    public function getMaxMemoryFileBytes(): int
    {
        $limit = CleanOptions::DEFAULT_MAX_MEMORY_FILE_BYTES;

        try {
            $fileModule = Yii::$app->getModule('file', false);
            $maxUpload = $fileModule !== null ? $fileModule->settings->get('maxFileSize') : null;
            if (is_numeric($maxUpload) && (int)$maxUpload > 0) {
                $limit = min($limit, (int)$maxUpload);
            }
        } catch (\Throwable) {
        }

        return $limit;
    }

    /**
     * @inheritdoc
     */
    public function getDescription()
    {
        return Yii::t('MetadataCleanerModule.base', 'Removes metadata (GPS included) from uploaded images, videos, audio files and documents.');
    }

    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/metadata-cleaner/admin']);
    }
}
