<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\file\models\File;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use Throwable;
use Yii;

/**
 * Writes and purges the log of removed metadata.
 */
class LogService
{
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_STORED = 'stored';
    public const SOURCE_CONSOLE = 'console';

    public function __construct(private readonly Module $module)
    {
    }

    /**
     * Writes a log row when the file had metadata and the log is enabled.
     * A logging failure never blocks the upload.
     */
    public function record(File $file, CleanResult $result, string $source): void
    {
        $mode = $this->module->getLogMode();
        if ($mode === Module::LOG_OFF || !$result->hadMetadata()) {
            return;
        }

        try {
            $fields = (new FieldExtractor())->extract($result->removed);

            $log = new MetadataLog();
            $log->file_id = $file->id;
            $log->file_guid = $file->guid;
            $log->file_name = mb_substr((string)$file->file_name, 0, 255);
            $log->format = (string)$result->format;
            $log->source = $source;
            $log->fields = json_encode(array_keys($fields), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $log->field_values = $mode === Module::LOG_FIELDS_AND_VALUES
                ? json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;
            $log->created_by = $file->created_by ?: (Yii::$app->has('user') && !Yii::$app->user->isGuest ? Yii::$app->user->id : null);
            $log->created_at = date('Y-m-d H:i:s');
            if (!$log->save(false)) {
                Yii::warning('Could not save metadata log row.', 'metadata-cleaner');
            }
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Deletes log rows older than the retention period.
     *
     * @return int number of deleted rows
     */
    public function purgeExpired(): int
    {
        $limit = date('Y-m-d H:i:s', time() - $this->module->getLogRetentionDays() * 86400);

        return MetadataLog::deleteAll(['<', 'created_at', $limit]);
    }
}
