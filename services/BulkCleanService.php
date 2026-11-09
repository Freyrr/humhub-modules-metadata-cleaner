<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\services;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileHistory;
use humhub\modules\metadataCleaner\cleaners\CleanResult;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use Throwable;
use Yii;

/**
 * Retroactive cleaning of files stored before the module was enabled.
 *
 * The job state lives in the module settings so it survives across
 * requests: the admin UI drives it chunk by chunk through
 * {@see process()}, which keeps every request short.
 */
class BulkCleanService
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';

    /** Files cleaned per process() call. */
    public const CHUNK_SIZE = 15;

    private const SETTING_KEY = 'bulkCleanJob';

    public function __construct(private readonly Module $module)
    {
    }

    /**
     * Current job state. Missing or malformed settings yield an idle state.
     *
     * @return array{status:string,dryRun:bool,lastId:int,total:int,processed:int,cleaned:int,unchanged:int,skipped:int,failed:int,currentFile:string|null,startedAt:string|null,updatedAt:string|null}
     */
    public function state(): array
    {
        $state = [
            'status' => self::STATUS_IDLE,
            'dryRun' => false,
            'lastId' => 0,
            'total' => 0,
            'processed' => 0,
            'cleaned' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'currentFile' => null,
            'startedAt' => null,
            'updatedAt' => null,
        ];

        try {
            $saved = json_decode((string)$this->module->settings->get(self::SETTING_KEY, ''), true);
            if (is_array($saved)) {
                foreach ($state as $key => $default) {
                    if (array_key_exists($key, $saved)) {
                        $state[$key] = $saved[$key];
                    }
                }
            }
        } catch (Throwable) {
        }

        $state['dryRun'] = (bool)$state['dryRun'];
        foreach (['lastId', 'total', 'processed', 'cleaned', 'unchanged', 'skipped', 'failed'] as $key) {
            $state[$key] = max(0, (int)$state[$key]);
        }
        $state['status'] = in_array($state['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_DONE], true)
            ? $state['status'] : self::STATUS_IDLE;

        return $state;
    }

    /**
     * Starts a new run from the first file. An already active job must be
     * stopped first.
     */
    public function start(bool $dryRun): array
    {
        $state = $this->state();
        if (in_array($state['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED], true)) {
            return $state;
        }

        try {
            $total = (int)File::find()->count();
        } catch (Throwable) {
            $total = 0;
        }

        $now = date('Y-m-d H:i:s');
        $state = array_merge($state, [
            'status' => self::STATUS_RUNNING,
            'dryRun' => $dryRun,
            'lastId' => 0,
            'total' => $total,
            'processed' => 0,
            'cleaned' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'currentFile' => null,
            'startedAt' => $now,
            'updatedAt' => $now,
        ]);
        $this->save($state);

        return $state;
    }

    /**
     * Pauses a running job. The next process() call does nothing until resumed.
     */
    public function pause(): array
    {
        $state = $this->state();
        if ($state['status'] === self::STATUS_RUNNING) {
            $state['status'] = self::STATUS_PAUSED;
            $state['updatedAt'] = date('Y-m-d H:i:s');
            $this->save($state);
        }

        return $state;
    }

    /**
     * Resumes a paused job.
     */
    public function resume(): array
    {
        $state = $this->state();
        if ($state['status'] === self::STATUS_PAUSED) {
            $state['status'] = self::STATUS_RUNNING;
            $state['updatedAt'] = date('Y-m-d H:i:s');
            $this->save($state);
        }

        return $state;
    }

    /**
     * Aborts the job and resets it to idle. Already cleaned files stay cleaned.
     */
    public function stop(): array
    {
        $state = $this->state();
        if (in_array($state['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_DONE], true)) {
            $state['status'] = self::STATUS_IDLE;
            $state['currentFile'] = null;
            $state['updatedAt'] = date('Y-m-d H:i:s');
            $this->save($state);
        }

        return $state;
    }

    /**
     * Processes up to CHUNK_SIZE files when the job is running.
     * Safe to call repeatedly (AJAX polling); does nothing when paused/idle/done.
     *
     * @return array the updated state
     */
    public function process(int $limit = self::CHUNK_SIZE): array
    {
        $state = $this->state();
        if ($state['status'] !== self::STATUS_RUNNING) {
            return $state;
        }

        $files = File::find()
            ->where(['>', 'id', $state['lastId']])
            ->orderBy(['id' => SORT_ASC])
            ->limit(max(1, $limit))
            ->all();

        foreach ($files as $file) {
            $state['lastId'] = (int)$file->id;
            $state['processed']++;
            $state['currentFile'] = (string)$file->file_name;

            try {
                $outcome = $this->processFile($file, (bool)$state['dryRun']);
            } catch (Throwable $e) {
                $outcome = 'failed';
                if (!$state['dryRun']) {
                    (new LogService($this->module))->recordFailure(
                        $file,
                        (string)$file->file_name,
                        null,
                        $e,
                        MetadataLog::STATUS_PASSED,
                        LogService::SOURCE_ADMIN,
                    );
                }
                Yii::error($e, 'metadata-cleaner');
            }

            $state[$outcome] = ($state[$outcome] ?? 0) + 1;
        }

        if (count($files) < max(1, $limit)) {
            $state['status'] = self::STATUS_DONE;
            $state['currentFile'] = null;
        }
        $state['updatedAt'] = date('Y-m-d H:i:s');
        $this->save($state);

        return $state;
    }

    /**
     * Cleans (or dry-runs) a single file and returns the outcome bucket:
     * cleaned, unchanged, skipped or failed.
     */
    private function processFile(File $file, bool $dryRun): string
    {
        if (!$file->getStore()->has()) {
            return 'skipped';
        }

        if ($dryRun) {
            $result = $this->dryRunFile($file);
            if ($result->format === null) {
                return 'skipped';
            }

            return $result->hadMetadata() ? 'cleaned' : 'unchanged';
        }

        $sizeBefore = $file->size !== null ? (int)$file->size : null;
        $result = Events::cleanStoredFile($file, $this->module);
        if ($result->changed) {
            // Previews were made from the old content.
            $file->getStore()->delete(null, ['file', FileHistory::VARIANT_PREFIX . '*']);
        }
        (new LogService($this->module))->record($file, $result, LogService::SOURCE_ADMIN, $sizeBefore);

        if ($result->format === null) {
            return 'skipped';
        }

        return $result->changed ? 'cleaned' : 'unchanged';
    }

    private function dryRunFile(File $file): CleanResult
    {
        $tmp = Events::copyToTemp($file, $this->module);
        if ($tmp === null) {
            return CleanResult::notHandled();
        }
        try {
            return $this->module->getCleanerService()->cleanPath($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function save(array $state): void
    {
        try {
            $this->module->settings->set(self::SETTING_KEY, json_encode($state));
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }
}
