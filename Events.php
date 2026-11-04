<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileUpload;
use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\services\LogService;
use Throwable;
use Yii;
use yii\helpers\Html;
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
     * Results of uploads cleaned in this request, by SHA1 of the cleaned
     * content, waiting for the stored-file event to log them with a file id.
     *
     * @var array<string, CleanResult>
     */
    private static array $cleanedUploads = [];

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
            $result = $module->getCleanerService()->cleanPath($path);
            if ($result->format === null) {
                return;
            }
            clearstatcache(true, $path);
            $size = (int)filesize($path);
            $file->uploadedFile->size = $size;
            $file->size = $size;
            self::$cleanedUploads[(string)sha1_file($path)] = $result;
        } catch (Throwable $e) {
            self::logFailure($file->file_name, $e, $module);
            if ($module->getOnError() === Module::ON_ERROR_REJECT) {
                $file->addError('uploadedFile', self::rejectMessage((string)$file->file_name));
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
        if (isset(self::$cleanedUploads[$hash])) {
            $result = self::$cleanedUploads[$hash];
            unset(self::$cleanedUploads[$hash]);
            (new LogService($module))->record($file, $result, LogService::SOURCE_UPLOAD);
            return;
        }

        try {
            $result = self::cleanStoredFile($file, $module);
        } catch (Throwable $e) {
            self::logFailure($file->file_name, $e, $module);
            if ($module->getOnError() === Module::ON_ERROR_REJECT) {
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

        (new LogService($module))->record($file, $result, LogService::SOURCE_STORED);
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
        $store = $file->getStore();
        $tmp = tempnam(sys_get_temp_dir(), 'mdc');
        if ($tmp === false) {
            throw new CleanerException('Cannot create temporary file.');
        }
        try {
            $in = $store->getContentStream();
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }

            $result = $module->getCleanerService()->cleanPath($tmp);
            if ($result->changed) {
                $store->setByPath($tmp);
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

    public static function onCronDailyRun(Event $event): void
    {
        $module = self::getModule();
        if ($module === null) {
            return;
        }
        try {
            (new LogService($module))->purgeExpired();
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
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
            'fileName' => Html::encode($fileName),
        ]);
    }

    private static function getModule(): ?Module
    {
        $module = Yii::$app->getModule('metadata-cleaner');

        return $module instanceof Module ? $module : null;
    }
}
