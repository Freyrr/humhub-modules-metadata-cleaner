<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\models;

use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\Module;
use Yii;
use yii\base\Model;

/**
 * Settings form of the admin page.
 */
class ConfigureForm extends Model
{
    public $logMode;
    public $logRetentionDays;
    public $keepOrientation;
    public $onError;
    public $formats;

    private Module $module;

    public function __construct(Module $module, $config = [])
    {
        $this->module = $module;
        parent::__construct($config);
    }

    public function init()
    {
        parent::init();
        $this->logMode = $this->module->getLogMode();
        $this->logRetentionDays = $this->module->getLogRetentionDays();
        $this->keepOrientation = $this->module->getKeepOrientation();
        $this->onError = $this->module->getOnError();
        $this->formats = $this->module->getEnabledFormats();
    }

    public function rules()
    {
        return [
            [['logMode', 'logRetentionDays', 'keepOrientation', 'onError'], 'required'],
            ['logMode', 'in', 'range' => array_keys(self::logModeOptions())],
            ['onError', 'in', 'range' => array_keys(self::onErrorOptions())],
            ['logRetentionDays', 'integer', 'min' => 1, 'max' => 3650],
            ['keepOrientation', 'boolean'],
            ['formats', 'filter', 'filter' => static fn($value) => is_array($value) ? array_values($value) : []],
            ['formats', 'each', 'rule' => ['in', 'range' => array_keys(self::formatOptions())]],
        ];
    }

    public function attributeLabels()
    {
        return [
            'logMode' => Yii::t('MetadataCleanerModule.base', 'Log of removed metadata'),
            'logRetentionDays' => Yii::t('MetadataCleanerModule.base', 'Keep log entries for (days)'),
            'keepOrientation' => Yii::t('MetadataCleanerModule.base', 'Keep the photo orientation tag'),
            'onError' => Yii::t('MetadataCleanerModule.base', 'When a file cannot be cleaned'),
            'formats' => Yii::t('MetadataCleanerModule.base', 'File types to clean'),
        ];
    }

    public function attributeHints()
    {
        return [
            'logMode' => Yii::t('MetadataCleanerModule.base', 'Values can include the GPS position and other personal data: store them only if you need them.'),
            'logRetentionDays' => Yii::t('MetadataCleanerModule.base', 'Older entries are deleted automatically every day.'),
            'keepOrientation' => Yii::t('MetadataCleanerModule.base', 'Without it, some photos taken with a phone are shown rotated.'),
            'formats' => Yii::t('MetadataCleanerModule.base', 'Files of unticked types are stored as uploaded, with their metadata.'),
        ];
    }

    public static function logModeOptions(): array
    {
        return [
            Module::LOG_OFF => Yii::t('MetadataCleanerModule.base', 'Off'),
            Module::LOG_FIELDS => Yii::t('MetadataCleanerModule.base', 'Field names only'),
            Module::LOG_FIELDS_AND_VALUES => Yii::t('MetadataCleanerModule.base', 'Field names and values'),
        ];
    }

    public static function formatOptions(): array
    {
        return [
            FormatDetector::FORMAT_JPEG => 'JPEG (.jpg, .jpeg)',
            FormatDetector::FORMAT_PNG => 'PNG (.png)',
            FormatDetector::FORMAT_WEBP => 'WebP (.webp)',
            FormatDetector::FORMAT_GIF => 'GIF (.gif)',
            FormatDetector::FORMAT_HEIF => 'HEIC / HEIF / AVIF (.heic, .heif, .avif)',
            FormatDetector::FORMAT_VIDEO => Yii::t('MetadataCleanerModule.base', 'Videos') . ' (.mp4, .mov, .m4v, .3gp, .webm, .mkv, .avi, .wmv)',
            FormatDetector::FORMAT_AUDIO => Yii::t('MetadataCleanerModule.base', 'Audio') . ' (.m4a, .mp3, .aac, .flac, .ogg, .opus, .wav, .aiff)',
            FormatDetector::FORMAT_TIFF => 'TIFF (.tif, .tiff)',
            FormatDetector::FORMAT_RAW => 'RAW (.dng, .cr2, .nef, .arw, .orf, .rw2)',
            FormatDetector::FORMAT_PDF => 'PDF (.pdf)',
            FormatDetector::FORMAT_OFFICE => 'Office / OpenDocument (.docx, .xlsx, .pptx, .odt, .ods, .odp)',
            FormatDetector::FORMAT_SVG => 'SVG (.svg)',
            FormatDetector::FORMAT_PSD => 'Photoshop (.psd, .psb)',
        ];
    }

    public static function onErrorOptions(): array
    {
        return [
            Module::ON_ERROR_PASS => Yii::t('MetadataCleanerModule.base', 'Accept the file unchanged'),
            Module::ON_ERROR_REJECT => Yii::t('MetadataCleanerModule.base', 'Reject the file'),
        ];
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $settings = $this->module->settings;
        $settings->set('logMode', $this->logMode);
        $settings->set('logRetentionDays', (int)$this->logRetentionDays);
        $settings->set('keepOrientation', (bool)$this->keepOrientation ? 1 : 0);
        $settings->set('onError', $this->onError);
        $settings->set('formats', implode(',', array_intersect(FormatDetector::ALL_FORMATS, (array)$this->formats)));

        return true;
    }
}
