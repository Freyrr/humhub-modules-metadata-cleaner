<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\commands;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileHistory;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\LogService;
use Throwable;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Cleans files uploaded before the module was enabled, and purges the log.
 *
 * Usage:
 *   php yii metadata-cleaner/clean [--dry-run] [--batch=100] [--from-id=0]
 *   php yii metadata-cleaner/purge-log
 */
class CleanController extends Controller
{
    /** Only report what would change, write nothing. */
    public bool $dryRun = false;

    /** Files loaded per query. */
    public int $batch = 100;

    /** Start after this file id, to resume an interrupted run. */
    public int $fromId = 0;

    public $defaultAction = 'clean';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), $actionID === 'clean' ? ['dryRun', 'batch', 'fromId'] : []);
    }

    public function optionAliases()
    {
        return ['n' => 'dryRun'];
    }

    /**
     * Removes metadata from all stored files.
     */
    public function actionClean(): int
    {
        $module = $this->getModule();
        $service = $module->getCleanerService();
        $log = new LogService($module);
        $counts = ['cleaned' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $lastId = max(0, $this->fromId);

        do {
            $files = File::find()->where(['>', 'id', $lastId])->orderBy(['id' => SORT_ASC])->limit(max(1, $this->batch))->all();
            foreach ($files as $file) {
                $lastId = $file->id;
                try {
                    if (!$file->getStore()->has()) {
                        $counts['skipped']++;
                        continue;
                    }
                    if ($this->dryRun) {
                        $result = $this->dryRunFile($file, $service);
                    } else {
                        $sizeBefore = $file->size !== null ? (int)$file->size : null;
                        $result = Events::cleanStoredFile($file, $module);
                        if ($result->changed) {
                            // Previews were made from the old content.
                            $file->getStore()->delete(null, ['file', FileHistory::VARIANT_PREFIX . '*']);
                        }
                        $log->record($file, $result, LogService::SOURCE_CONSOLE, $sizeBefore);
                    }
                    if ($result->format === null) {
                        $counts['skipped']++;
                    } elseif ($result->changed || ($this->dryRun && $result->hadMetadata())) {
                        $counts['cleaned']++;
                        $this->stdout(sprintf("%s #%d %s\n", $this->dryRun ? 'would clean' : 'cleaned', $file->id, $file->file_name));
                    } else {
                        $counts['unchanged']++;
                    }
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $this->stderr(sprintf("failed #%d %s: %s\n", $file->id, $file->file_name, $e->getMessage()), Console::FG_RED);
                    if (!$this->dryRun) {
                        $log->recordFailure($file, (string)$file->file_name, null, $e, MetadataLog::STATUS_PASSED, LogService::SOURCE_CONSOLE);
                    }
                }
            }
        } while (count($files) > 0);

        $this->stdout(sprintf(
            "\n%s: %d, unchanged: %d, not images or videos: %d, failed: %d. Last file id: %d\n",
            $this->dryRun ? 'Would clean' : 'Cleaned',
            $counts['cleaned'],
            $counts['unchanged'],
            $counts['skipped'],
            $counts['failed'],
            $lastId,
        ), Console::BOLD);

        return $counts['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Deletes log entries older than the retention period.
     */
    public function actionPurgeLog(): int
    {
        $count = (new LogService($this->getModule()))->purgeExpired();
        $this->stdout("Deleted $count log entries.\n");

        return ExitCode::OK;
    }

    private function dryRunFile(File $file, $service)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdc');
        try {
            $in = $file->getStore()->getContentStream();
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }

            return $service->cleanPath($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function getModule(): Module
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('metadata-cleaner');

        return $module;
    }
}
