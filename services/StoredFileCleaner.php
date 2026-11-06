<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileHistory;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\Module;
use Throwable;
use Yii;

/**
 * Cleans one already stored file. Used by the cleanup of existing files
 * (queue job and console command).
 *
 * Never throws: every error is logged and returned as OUTCOME_FAILED, so a
 * cleanup goes on with the next file. A file that cannot be cleaned is kept
 * unchanged, whatever the "onError" setting: the reject policy only applies
 * to new uploads.
 */
class StoredFileCleaner
{
    /** Metadata removed. */
    public const OUTCOME_CLEANED = 'cleaned';
    /** Format cleaned, but the file had no metadata. */
    public const OUTCOME_UNCHANGED = 'unchanged';
    /** Format not selected or not handled, or the content is missing. */
    public const OUTCOME_SKIPPED = 'skipped';
    /** Cleaning failed, the file was kept unchanged. */
    public const OUTCOME_FAILED = 'failed';

    private LogService $log;

    /** Bytes removed from the last cleaned file. */
    public int $lastBytesSaved = 0;

    /** Message of the last error. */
    public ?string $lastError = null;

    /**
     * @param string[]|null $formats formats to clean, null for the configured ones
     */
    public function __construct(
        private readonly Module $module,
        private readonly ?array $formats = null,
        private readonly string $source = LogService::SOURCE_BULK,
    ) {
        $this->log = new LogService($module);
    }

    public function clean(File $file): string
    {
        $this->lastBytesSaved = 0;
        $this->lastError = null;
        try {
            if (!$file->getStore()->has()) {
                return self::OUTCOME_SKIPPED;
            }
            $sizeBefore = $file->size !== null ? (int)$file->size : null;
            $result = Events::cleanStoredFile($file, $this->module, $this->formats);
            if ($result->changed) {
                // Previews were made from the old content.
                $file->getStore()->delete(null, ['file', FileHistory::VARIANT_PREFIX . '*']);
            }
            $this->log->record($file, $result, $this->source, $sizeBefore);
            if ($result->format === null) {
                return self::OUTCOME_SKIPPED;
            }
            if ($result->changed) {
                if ($sizeBefore !== null && $file->size !== null) {
                    $this->lastBytesSaved = max(0, $sizeBefore - (int)$file->size);
                }

                return self::OUTCOME_CLEANED;
            }

            return self::OUTCOME_UNCHANGED;
        } catch (Throwable $e) {
            $this->lastError = mb_substr(sprintf('#%d %s: %s', $file->id, (string)$file->file_name, $e->getMessage() ?: get_class($e)), 0, 255);
            Yii::warning('Metadata of stored file ' . $this->lastError, 'metadata-cleaner');
            try {
                $this->log->recordFailure($file, (string)$file->file_name, null, $e, MetadataLog::STATUS_PASSED, $this->source);
            } catch (Throwable $logError) {
                Yii::error($logError, 'metadata-cleaner');
            }

            return self::OUTCOME_FAILED;
        }
    }
}
