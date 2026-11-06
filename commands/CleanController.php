<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\commands;

use humhub\modules\file\models\File;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\LogService;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use humhub\modules\metadataCleaner\services\StoredFileCleaner;
use Throwable;
use Yii;
use yii\base\InlineAction;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Purges expired log entries.
 *
 * Usage:
 *   php yii metadata-cleaner/purge-log
 *
 * The bulk cleaning of all stored files (`metadata-cleaner/clean`) is not
 * listed by `php yii help`: the admin page starts it as queue jobs, with
 * progress and resume ({@see \humhub\modules\metadataCleaner\services\CleanupService}).
 * The console action is kept for operators who prefer the command line. It is
 * run by {@see runClean()} through {@see createAction()}.
 */
class CleanController extends Controller
{
    /** Only report what would change, write nothing. */
    public bool $dryRun = false;

    /** Files loaded per query. */
    public int $batch = 100;

    /** Start after this file id, to resume an interrupted run. */
    public int $fromId = 0;

    public $defaultAction = 'purge-log';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), $actionID === 'clean' ? ['dryRun', 'batch', 'fromId'] : []);
    }

    public function optionAliases()
    {
        return ['n' => 'dryRun'];
    }

    /**
     * Maps the hidden "clean" action to {@see runClean()}. A method named
     * actionClean() would be listed by `php yii help`.
     */
    public function createAction($id)
    {
        if ($id === 'clean') {
            return new InlineAction('clean', $this, 'runClean');
        }

        return parent::createAction($id);
    }

    /**
     * Removes metadata from all stored files (hidden "clean" action).
     */
    public function runClean(): int
    {
        $module = $this->getModule();
        $service = $module->getCleanerService();
        $cleaner = new StoredFileCleaner($module, null, LogService::SOURCE_CONSOLE);
        $counts = ['cleaned' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $lastId = max(0, $this->fromId);

        do {
            $files = File::find()->where(['>', 'id', $lastId])->orderBy(['id' => SORT_ASC])->limit(max(1, $this->batch))->all();
            foreach ($files as $file) {
                $lastId = $file->id;
                if ($this->dryRun) {
                    $outcome = $this->dryRunFile($file, $service);
                } else {
                    $outcome = $cleaner->clean($file);
                }
                $counts[$outcome]++;
                if ($outcome === StoredFileCleaner::OUTCOME_CLEANED) {
                    $this->stdout(sprintf("%s #%d %s\n", $this->dryRun ? 'would clean' : 'cleaned', $file->id, $file->file_name));
                } elseif ($outcome === StoredFileCleaner::OUTCOME_FAILED) {
                    $this->stderr(sprintf("failed %s\n", $cleaner->lastError ?? ('#' . $file->id)), Console::FG_RED);
                }
            }
        } while (count($files) > 0);

        $this->stdout(sprintf(
            "\n%s: %d, unchanged: %d, skipped (format not cleaned or content missing): %d, failed: %d. Last file id: %d\n",
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

    /**
     * Deletes monthly statistics buckets older than the retention period.
     */
    public function actionPurgeStats(): int
    {
        $count = MetadataStats::purgeOlderThan($this->getModule()->getStatsRetentionMonths());
        $this->stdout("Deleted $count stats buckets.\n");

        return ExitCode::OK;
    }

    private function dryRunFile(File $file, MetadataCleanerService $service): string
    {
        try {
            if (!$file->getStore()->has()) {
                return StoredFileCleaner::OUTCOME_SKIPPED;
            }
            $tmp = Events::copyToTemp($file, $this->getModule());
            if ($tmp === null) {
                return StoredFileCleaner::OUTCOME_SKIPPED;
            }
            try {
                $result = $service->cleanPath($tmp);
            } finally {
                @unlink($tmp);
            }
        } catch (Throwable $e) {
            $this->stderr(sprintf("failed #%d %s: %s\n", $file->id, $file->file_name, $e->getMessage()), Console::FG_RED);

            return StoredFileCleaner::OUTCOME_FAILED;
        }
        if ($result->format === null) {
            return StoredFileCleaner::OUTCOME_SKIPPED;
        }

        return $result->hadMetadata() ? StoredFileCleaner::OUTCOME_CLEANED : StoredFileCleaner::OUTCOME_UNCHANGED;
    }

    private function getModule(): Module
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('metadata-cleaner');

        return $module;
    }
}
