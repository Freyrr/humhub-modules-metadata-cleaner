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

            $log = $this->newRow($file, (string)$file->file_name, $source);
            $log->status = MetadataLog::STATUS_CLEANED;
            $log->format = $result->format;
            $log->fields = json_encode(array_keys($fields), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $log->field_values = $mode === Module::LOG_FIELDS_AND_VALUES
                ? json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;
            $this->save($log);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Writes a log row for a file that could not be cleaned, whether it had
     * metadata or not, unless the log is switched off.
     *
     * @param File|null $file null when the upload was rejected before a record existed
     * @param string $status MetadataLog::STATUS_PASSED or MetadataLog::STATUS_REJECTED
     */
    public function recordFailure(?File $file, string $fileName, ?string $format, Throwable $error, string $status, string $source): void
    {
        if ($this->module->getLogMode() === Module::LOG_OFF) {
            return;
        }

        try {
            $log = $this->newRow($file, $fileName, $source);
            $log->status = $status;
            $log->format = $format;
            $log->error = mb_substr($error->getMessage() ?: get_class($error), 0, 255);
            $this->save($log);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    private function newRow(?File $file, string $fileName, string $source): MetadataLog
    {
        $log = new MetadataLog();
        $log->file_id = $file?->id;
        $log->file_guid = $file?->guid;
        $log->file_name = mb_substr($fileName, 0, 255);
        $log->source = $source;
        $log->created_by = $file?->created_by ?: $this->currentUserId();
        $log->created_at = date('Y-m-d H:i:s');

        return $log;
    }

    private function save(MetadataLog $log): void
    {
        if (!$log->save(false)) {
            Yii::warning('Could not save metadata log row.', 'metadata-cleaner');
        }
    }

    private function currentUserId(): ?int
    {
        try {
            $user = Yii::$app->get('user', false);

            return $user !== null && !$user->isGuest ? (int)$user->id : null;
        } catch (Throwable) {
            return null;
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
