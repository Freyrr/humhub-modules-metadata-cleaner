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
 * Writes and purges the log of cleaning runs.
 *
 * Every method catches its own errors: logging must never break an upload.
 */
class LogService
{
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_STORED = 'stored';
    public const SOURCE_CONSOLE = 'console';

    /** Largest JSON stored in the metadata column (TEXT holds 65535 bytes). */
    public const MAX_METADATA_BYTES = 60000;

    public function __construct(private readonly Module $module)
    {
    }

    /**
     * Writes a row when the file had metadata and the log is enabled.
     */
    public function record(File $file, CleanResult $result, string $source, ?int $sizeBefore = null): void
    {
        $mode = $this->module->getLogMode();
        if ($mode === Module::LOG_OFF || !$result->hadMetadata()) {
            return;
        }

        try {
            $fields = (new FieldExtractor())->extract($result->removed);
            if ($mode !== Module::LOG_FIELDS_AND_VALUES) {
                $fields = array_fill_keys(array_keys($fields), '');
            }

            $log = $this->newRow($file, (string)$file->file_name, $source);
            $log->status = MetadataLog::STATUS_CLEANED;
            $log->format = $result->format;
            $log->metadata = self::encode($fields);
            $log->size_before = $sizeBefore;
            $log->size_after = $file->size !== null ? (int)$file->size : null;
            $this->save($log);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Writes a row for a file that could not be cleaned, whether it had
     * metadata or not, unless the log is switched off.
     *
     * @param File|null $file null when the upload was rejected before a record existed
     * @param string $status MetadataLog::STATUS_PASSED or MetadataLog::STATUS_REJECTED
     */
    public function recordFailure(?File $file, string $fileName, ?string $format, Throwable $error, string $status, string $source, ?int $size = null): void
    {
        if ($this->module->getLogMode() === Module::LOG_OFF) {
            return;
        }

        try {
            $log = $this->newRow($file, $fileName, $source);
            $log->status = $status;
            $log->format = $format;
            $log->error = mb_substr($error->getMessage() ?: get_class($error), 0, 255);
            $log->size_before = $size ?? ($file?->size !== null ? (int)$file->size : null);
            $log->size_after = $status === MetadataLog::STATUS_PASSED ? $log->size_before : null;
            $this->save($log);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Deletes rows older than the retention period.
     *
     * @return int number of deleted rows
     */
    public function purgeExpired(): int
    {
        $limit = date('Y-m-d H:i:s', time() - $this->module->getLogRetentionDays() * 86400);

        return MetadataLog::deleteAll(['<', 'created_at', $limit]);
    }

    /**
     * JSON of the fields, shortened to fit the column.
     *
     * @param array<string, string> $fields
     */
    public static function encode(array $fields): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string)json_encode((object)$fields, $flags);
        while (strlen($json) > self::MAX_METADATA_BYTES && $fields !== []) {
            array_pop($fields);
            $json = (string)json_encode((object)($fields + ['…' => 'truncated']), $flags);
        }

        return $json;
    }

    private function newRow(?File $file, string $fileName, string $source): MetadataLog
    {
        $log = new MetadataLog();
        $log->file_id = $file?->id;
        $log->file_guid = $file?->guid;
        $log->file_name = mb_substr($fileName, 0, 255);
        $log->mime_type = $file?->mime_type !== null ? mb_substr((string)$file->mime_type, 0, 150) : null;
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
}
