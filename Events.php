<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileUpload;
use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\services\LogService;
use Throwable;
use Yii;
use yii\base\Event;
use yii\base\UserException;
use yii\web\UploadedFile;

/**
 * Event handlers.
 *
 * Files are cleaned in two places:
 * 1. after a FileUpload is validated, on the uploaded temporary file;
 * 2. after any new file content is stored (API, imports, other modules...),
 *    as a safety net.
 *
 * Every error is caught. Depending on the "onError" setting the file is then
 * either passed to HumHub unchanged (default) or rejected: as a validation
 * error on upload, or by deleting the stored file afterwards.
 * Files are only ever replaced atomically, so a failure never leaves a
 * half-written file behind.
 */
class Events
{
    /**
     * SHA1 of upload contents already handled by onFileUploadAfterValidate
     * in this request, so the stored-file event does not clean them again.
     * The log rows are written at validate time and linked to the File by
     * LogService::claimUpload().
     *
     * @var array<string, true>
     */
    private static array $handledUploads = [];

    public static function onFileUploadAfterValidate(Event $event): void
    {
        $file = $event->sender;
        if (!$file instanceof FileUpload || $file->hasErrors() || !$file->uploadedFile instanceof UploadedFile) {
            return;
        }
        try {
            $module = self::getModule();
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
            return;
        }
        if ($module === null) {
            return;
        }

        try {
            $path = $file->uploadedFile->tempName;
            $sizeBefore = (int)@filesize($path);
            $result = $module->getCleanerService()->cleanPath($path);
            if ($result->format === null) {
                return;
            }
            clearstatcache(true, $path);
            $size = (int)filesize($path);
            $file->uploadedFile->size = $size;
            $file->size = $size;
            $hash = (string)sha1_file($path);
            self::$handledUploads[$hash] = true;
            (new LogService($module))->recordUpload($file, $result, $sizeBefore, $size, $hash);
        } catch (Throwable $e) {
            self::logFailure($file->file_name, $e, $module);
            try {
                $format = self::detectFormat($file->uploadedFile->tempName);
                if ($module->getOnError() === Module::ON_ERROR_REJECT) {
                    $file->addError('uploadedFile', self::rejectMessage((string)$file->file_name));
                    (new LogService($module))->recordFailure(null, (string)$file->file_name, $format, $e, MetadataLog::STATUS_REJECTED, LogService::SOURCE_UPLOAD, (int)@filesize($file->uploadedFile->tempName));
                } else {
                    $hash = (string)sha1_file($file->uploadedFile->tempName);
                    self::$handledUploads[$hash] = true;
                    (new LogService($module))->recordFailure(null, (string)$file->file_name, $format, $e, MetadataLog::STATUS_PASSED, LogService::SOURCE_UPLOAD, (int)@filesize($file->uploadedFile->tempName), $hash);
                }
            } catch (Throwable $logError) {
                Yii::error($logError, 'metadata-cleaner');
            }
        }
    }

    public static function onAfterNewStoredFile(Event $event): void
    {
        $file = $event->sender;
        if (!$file instanceof File) {
            return;
        }
        try {
            $module = self::getModule();
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
            return;
        }
        if ($module === null) {
            return;
        }

        $hash = (string)$file->hash_sha1;
        $seen = isset(self::$handledUploads[$hash]);
        unset(self::$handledUploads[$hash]);
        $log = new LogService($module);
        if ($seen) {
            // The upload was cleaned in this request: link its log row.
            $log->claimUpload($file);
            return;
        }
        if ($log->claimUpload($file)) {
            // The upload was cleaned in an earlier request and is already clean.
            return;
        }

        $sizeBefore = $file->size !== null ? (int)$file->size : null;
        try {
            $result = self::cleanStoredFile($file, $module);
        } catch (Throwable $e) {
            self::logFailure($file->file_name, $e, $module);
            $reject = $module->getOnError() === Module::ON_ERROR_REJECT;
            (new LogService($module))->recordFailure(
                $file,
                (string)$file->file_name,
                null,
                $e,
                $reject ? MetadataLog::STATUS_REJECTED : MetadataLog::STATUS_PASSED,
                LogService::SOURCE_STORED,
            );
            if ($reject) {
                $name = (string)$file->file_name;
                try {
                    $file->delete();
                } catch (Throwable $deleteError) {
                    Yii::error($deleteError, 'metadata-cleaner');
                }
                throw new UserException(self::rejectMessage($name));
            }
            return;
        }

        (new LogService($module))->record($file, $result, LogService::SOURCE_STORED, $sizeBefore);
    }

    /**
     * Cleans the stored original of a file and updates its hash and size.
     * Works on any HumHub storage: the content is copied to a local temporary
     * file, cleaned there, and written back only when it changed.
     *
     * @throws CleanerException
     */
    public static function cleanStoredFile(File $file, Module $module): CleanResult
    {
        $tmp = self::copyToTemp($file, $module);
        if ($tmp === null) {
            return CleanResult::notHandled();
        }
        try {
            $result = $module->getCleanerService()->cleanPath($tmp);
            if ($result->changed) {
                $file->getStore()->setByPath($tmp);
                $file->updateAttributes([
                    'hash_sha1' => sha1_file($tmp),
                    'size' => filesize($tmp),
                ]);
            }

            return $result;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Copies the stored content of a file to a local temporary file.
     *
     * The first bytes of the stream are enough to tell the format of every
     * file the module cleans: when they can be read and the format is not a
     * handled one, the copy is skipped and null is returned. When they
     * cannot be read, the whole content is copied and the detection runs on
     * the temporary file as before.
     *
     * @return string|null path of the temporary file, or null when the copy was skipped
     * @throws CleanerException
     */
    public static function copyToTemp(File $file, Module $module): ?string
    {
        $in = $file->getStore()->getContentStream();
        if (!is_resource($in)) {
            throw new CleanerException('Cannot read file.');
        }
        $head = @stream_get_contents($in, 64);
        if (is_string($head)) {
            $format = FormatDetector::detectFromBytes($head);
            $enabled = $module->getEnabledFormats();
            if ($format === FormatDetector::FORMAT_TIFF) {
                // A TIFF header can also begin a DNG (the raw group) or an
                // unhandled camera RAW: the full detection reads the tags.
                $enabled = in_array(FormatDetector::FORMAT_TIFF, $enabled, true)
                    || in_array(FormatDetector::FORMAT_RAW, $enabled, true);
            } else {
                $enabled = $format !== null && in_array($format, $enabled, true);
            }
            // An XML file needs more bytes to tell an SVG apart; the others
            // are decided for good on these 64 bytes.
            $maybeSvg = $format === null && FormatDetector::looksLikeXml($head);
            if (!$enabled && !$maybeSvg) {
                fclose($in);

                return null;
            }
        } else {
            @fseek($in, 0);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mdc');
        if ($tmp === false) {
            fclose($in);
            throw new CleanerException('Cannot create temporary file.');
        }
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            @unlink($tmp);
            throw new CleanerException('Cannot open temporary file.');
        }
        if ($head !== false && $head !== '') {
            fwrite($out, $head);
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        return $tmp;
    }

    public static function onCronDailyRun(Event $event): void
    {
        $module = self::getModule();
        if ($module === null) {
            return;
        }
        try {
            (new LogService($module))->purgeExpired();
            MetadataStats::purgeOlderThan($module->getStatsRetentionMonths());
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    private static function detectFormat(string $path): ?string
    {
        try {
            return FormatDetector::detect($path);
        } catch (Throwable) {
            return null;
        }
    }

    private static function logFailure(?string $fileName, Throwable $e, Module $module): void
    {
        $message = sprintf(
            'Metadata of "%s" not removed (%s): %s',
            (string)$fileName,
            $module->getOnError() === Module::ON_ERROR_REJECT ? 'file rejected' : 'file kept unchanged',
            $e->getMessage(),
        );
        if ($e instanceof CleanerException) {
            Yii::warning($message, 'metadata-cleaner');
        } else {
            Yii::error($message . "\n" . $e, 'metadata-cleaner');
        }
    }

    private static function rejectMessage(string $fileName): string
    {
        return Yii::t('MetadataCleanerModule.base', 'The file {fileName} was rejected because its metadata could not be removed.', [
            'fileName' => $fileName,
        ]);
    }

    private static function getModule(): ?Module
    {
        $module = Yii::$app->getModule('metadata-cleaner');

        return $module instanceof Module ? $module : null;
    }
}
