<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\jobs;

use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\CleanupService;
use humhub\modules\queue\LongRunningActiveJob;
use Throwable;
use Yii;

/**
 * Cleans one batch of a cleanup of existing files, then queues the next
 * batch. See {@see CleanupService}.
 */
class CleanExistingFilesJob extends LongRunningActiveJob
{
    public int $runId = 0;

    public string $token = '';

    /**
     * @inheritdoc
     */
    public function run()
    {
        $module = Yii::$app->getModule('metadata-cleaner');
        if (!$module instanceof Module) {
            return;
        }

        $service = new CleanupService($module);
        try {
            $more = $service->runBatch($this->runId, $this->token);
        } catch (Throwable $e) {
            // Unexpected error outside a file (database...): the run stays
            // "running", is shown as stalled and can be resumed.
            Yii::error($e, 'metadata-cleaner');
            return;
        }

        if ($more) {
            Yii::$app->queue->push(new self(['runId' => $this->runId, 'token' => $this->token]));
        }
    }
}
