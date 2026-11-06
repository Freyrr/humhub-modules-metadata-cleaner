<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\file\models\File;
use humhub\modules\metadataCleaner\jobs\CleanExistingFilesJob;
use humhub\modules\metadataCleaner\models\CleanRun;
use humhub\modules\metadataCleaner\Module;
use Throwable;
use Yii;

/**
 * Cleanup of the files stored before the module was enabled.
 *
 * A run is processed by a chain of queue jobs. Each job cleans a batch of
 * files (limited in number and time), saves where it stopped and queues the
 * next one. A failing file is counted and skipped: the run never blocks on
 * errors. When the queue stops (server restart, job killed), "Resume" starts
 * a new chain from the last file processed.
 *
 * Only one chain works on a run: every job carries the run token, and
 * resuming changes the token, so a late job of an older chain does nothing.
 */
class CleanupService
{
    /** Most files cleaned by one job. */
    public int $batchSize = 100;

    /** Seconds after which a job stops and queues the next batch. */
    public int $batchSeconds = 20;

    private const MUTEX = 'metadata-cleaner-cleanup';

    public function __construct(private readonly Module $module)
    {
    }

    /**
     * Starts a cleanup of all files stored so far, from the first file, with
     * the formats selected in the settings. Returns the running cleanup if
     * one exists already, unless $restart is true.
     */
    public function start(bool $restart = false): CleanRun
    {
        $current = CleanRun::findCurrent();
        if ($current !== null && $current->isRunning() && !$restart) {
            return $current;
        }
        if ($current !== null) {
            // The paused (or restarted) cleanup is replaced by the new one.
            $current->updateAttributes(['status' => CleanRun::STATUS_CANCELLED, 'finished_at' => date('Y-m-d H:i:s')]);
        }

        $maxId = (int)File::find()->max('id');
        $now = date('Y-m-d H:i:s');
        $run = new CleanRun();
        $run->status = CleanRun::STATUS_RUNNING;
        $run->token = CleanRun::newToken();
        $run->formats = implode(',', $this->module->getEnabledFormats());
        $run->last_file_id = 0;
        $run->max_file_id = $maxId;
        $run->total = (int)File::find()->where(['<=', 'id', $maxId])->count();
        $run->created_by = $this->currentUserId();
        $run->started_at = $now;
        $run->updated_at = $now;
        $run->save(false);

        $this->queue($run);

        return $run;
    }

    /**
     * Stops a running cleanup after the current file.
     */
    public function stop(CleanRun $run): void
    {
        if ($run->isRunning()) {
            $run->updateAttributes(['status' => CleanRun::STATUS_STOPPED, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Continues a paused or stalled cleanup from the last file processed.
     * When the selected formats changed since it started, a new cleanup
     * starts from the first file instead.
     */
    public function resume(CleanRun $run): void
    {
        if ($run->status !== CleanRun::STATUS_RUNNING && $run->status !== CleanRun::STATUS_STOPPED) {
            return;
        }
        if (!$run->hasFormats($this->module->getEnabledFormats())) {
            $this->start(true);
            return;
        }
        $run->updateAttributes([
            'status' => CleanRun::STATUS_RUNNING,
            'token' => CleanRun::newToken(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->queue($run);
    }

    /**
     * Called after the settings are saved: when the selected formats changed,
     * a running cleanup restarts from the first file with the new formats.
     * A paused one restarts from the first file when it is resumed.
     */
    public function onSettingsSaved(): void
    {
        $run = CleanRun::findCurrent();
        if ($run !== null && $run->isRunning() && !$run->hasFormats($this->module->getEnabledFormats())) {
            $this->start(true);
        }
    }

    /**
     * Cleans the next batch of a run. Called by the queue job.
     *
     * @return bool true when more files remain and the next batch must be queued
     */
    public function runBatch(int $runId, string $token): bool
    {
        if (!$this->isCurrent(CleanRun::findOne(['id' => $runId]), $token)) {
            return false;
        }
        $mutex = Yii::$app->mutex;
        if (!$mutex->acquire(self::MUTEX, 30)) {
            // A batch of an older chain is still working: try again later.
            return true;
        }
        try {
            $run = CleanRun::findOne(['id' => $runId]);
            if (!$this->isCurrent($run, $token)) {
                return false;
            }

            $cleaner = new StoredFileCleaner($this->module, $run->getFormatList());
            $deadline = microtime(true) + max(1, $this->batchSeconds);
            $files = File::find()
                ->where(['>', 'id', $run->last_file_id])
                ->andWhere(['<=', 'id', $run->max_file_id])
                ->orderBy(['id' => SORT_ASC])
                ->limit(max(1, $this->batchSize))
                ->all();

            foreach ($files as $file) {
                $outcome = $cleaner->clean($file);
                $run->last_file_id = $file->id;
                $run->processed++;
                $run->{$outcome}++;
                $run->bytes_saved += $cleaner->lastBytesSaved;
                if ($cleaner->lastError !== null) {
                    $run->last_error = $cleaner->lastError;
                }
                $run->updated_at = date('Y-m-d H:i:s');
                // Saved after every file: a crash loses at most one file of progress.
                $this->saveProgress($run);

                if (microtime(true) > $deadline) {
                    break;
                }
            }

            $run->refresh();
            if (!$this->isCurrent($run, $token)) {
                // Stopped from the admin page, or resumed by another chain.
                return false;
            }

            $more = File::find()
                ->where(['>', 'id', $run->last_file_id])
                ->andWhere(['<=', 'id', $run->max_file_id])
                ->exists();
            if (!$more) {
                // Files deleted during the run are not counted as processed.
                $run->updateAttributes([
                    'status' => CleanRun::STATUS_COMPLETED,
                    'total' => $run->processed,
                    'finished_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $more;
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    public function queue(CleanRun $run): void
    {
        Yii::$app->queue->push(new CleanExistingFilesJob(['runId' => $run->id, 'token' => $run->token]));
    }

    private function isCurrent(?CleanRun $run, string $token): bool
    {
        return $run !== null && $run->isRunning() && hash_equals($run->token, $token);
    }

    private function saveProgress(CleanRun $run): void
    {
        // Only the progress columns: the status may have been changed by
        // the admin page in the meantime.
        $run->updateAttributes(['last_file_id', 'processed', 'cleaned', 'unchanged', 'skipped', 'failed', 'bytes_saved', 'last_error', 'updated_at']);
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
