<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\file\models\File;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataStats;
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
        if ($result->format !== null) {
            $sizeAfter = $file->size !== null ? (int)$file->size : null;
            $saved = $result->hadMetadata() && $sizeBefore !== null && $sizeAfter !== null
                ? max(0, $sizeBefore - $sizeAfter)
                : 0;
            MetadataStats::record(
                $result->format,
                $result->hadMetadata() ? MetadataStats::STATUS_CLEANED : MetadataStats::STATUS_CLEAN,
                $saved,
            );
        }

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
     * Writes a row at upload time, when the cleaned file has no File record
     * yet. The row is linked to the stored file later by claimUpload(),
     * even when that happens in a different request.
     *
     * @param File $file the FileUpload model: its id is still null
     * @param int $sizeBefore size of the upload before cleaning
     * @param int $sizeAfter size of the cleaned upload
     * @param string $contentHash SHA1 of the cleaned content
     */
    public function recordUpload(File $file, CleanResult $result, int $sizeBefore, int $sizeAfter, string $contentHash): void
    {
        if ($result->format !== null) {
            MetadataStats::record(
                $result->format,
                $result->hadMetadata() ? MetadataStats::STATUS_CLEANED : MetadataStats::STATUS_CLEAN,
                $result->hadMetadata() ? max(0, $sizeBefore - $sizeAfter) : 0,
            );
        }

        $mode = $this->module->getLogMode();
        if ($mode === Module::LOG_OFF || !$result->hadMetadata()) {
            return;
        }

        try {
            $fields = (new FieldExtractor())->extract($result->removed);
            if ($mode !== Module::LOG_FIELDS_AND_VALUES) {
                $fields = array_fill_keys(array_keys($fields), '');
            }

            $log = $this->newRow($file, (string)$file->file_name, self::SOURCE_UPLOAD);
            $log->status = MetadataLog::STATUS_CLEANED;
            $log->format = $result->format;
            $log->metadata = self::encode($fields);
            $log->size_before = $sizeBefore;
            $log->size_after = $sizeAfter;
            $log->content_hash = $contentHash;
            $this->save($log);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Links a stored file to the log row written when its upload was
     * cleaned. Matching is by content hash and uploader, so it works across
     * requests.
     *
     * @return bool true when a pending upload row was found and linked
     */
    public function claimUpload(File $file): bool
    {
        if ($this->module->getLogMode() === Module::LOG_OFF || $file->hash_sha1 === null || $file->hash_sha1 === '') {
            return false;
        }

        try {
            $query = MetadataLog::find()
                ->where(['content_hash' => $file->hash_sha1, 'source' => self::SOURCE_UPLOAD, 'file_id' => null])
                ->orderBy(['id' => SORT_ASC]);
            if ($file->created_by !== null) {
                $query->andWhere(['created_by' => $file->created_by]);
            }
            $log = $query->one();
            if ($log === null) {
                return false;
            }
            $log->updateAttributes([
                'file_id' => $file->id,
                'file_guid' => $file->guid,
                'mime_type' => $log->mime_type ?? ($file->mime_type !== null ? mb_substr((string)$file->mime_type, 0, 150) : null),
            ]);

            return true;
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');

            return false;
        }
    }

    /**
     * Writes a row for a file that could not be cleaned, whether it had
     * metadata or not, unless the log is switched off.
     *
     * @param File|null $file null when the upload was rejected before a record existed
     * @param string $status MetadataLog::STATUS_PASSED or MetadataLog::STATUS_REJECTED
     * @param string|null $contentHash SHA1 of the content stored (unchanged upload), to link the row later
     */
    public function recordFailure(?File $file, string $fileName, ?string $format, Throwable $error, string $status, string $source, ?int $size = null, ?string $contentHash = null): void
    {
        MetadataStats::record(
            $format,
            $status === MetadataLog::STATUS_REJECTED ? MetadataStats::STATUS_REJECTED : MetadataStats::STATUS_PASSED,
        );

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
            $log->content_hash = $contentHash;
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
        try {
            $limit = date('Y-m-d H:i:s', time() - $this->module->getLogRetentionDays() * 86400);

            return MetadataLog::deleteAll(['<', 'created_at', $limit]);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');

            return 0;
        }
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
