<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\modules\metadataCleaner\services\BulkCleanService;

/* @var $this View */
/* @var $bulkState array */

$bulkPercent = $bulkState['total'] > 0 ? min(100, (int)round($bulkState['processed'] / $bulkState['total'] * 100)) : 0;
?>
<p class="text-body-secondary">
    <?= Yii::t('MetadataCleanerModule.base', 'Files stored before the module was enabled are not cleaned automatically. A job cleans them in small batches while this page is open; it can be paused, resumed or stopped at any time.') ?>
</p>
<div class="bulk-clean-controls d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="metadata-cleaner-bulk-dryrun">
        <label class="form-check-label" for="metadata-cleaner-bulk-dryrun">
            <?= Yii::t('MetadataCleanerModule.base', 'Dry run (report what would change, modify nothing)') ?>
        </label>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="metadata-cleaner-bulk-start">
        <?= Yii::t('MetadataCleanerModule.base', 'Start') ?>
    </button>
    <button type="button" class="btn btn-warning btn-sm" id="metadata-cleaner-bulk-pause">
        <?= Yii::t('MetadataCleanerModule.base', 'Pause') ?>
    </button>
    <button type="button" class="btn btn-primary btn-sm" id="metadata-cleaner-bulk-resume">
        <?= Yii::t('MetadataCleanerModule.base', 'Resume') ?>
    </button>
    <button type="button" class="btn btn-danger btn-sm" id="metadata-cleaner-bulk-stop">
        <?= Yii::t('MetadataCleanerModule.base', 'Stop') ?>
    </button>
    <span class="bulk-clean-status text-body-secondary" id="metadata-cleaner-bulk-status"></span>
</div>
<div id="metadata-cleaner-bulk-progress" style="display: <?= in_array($bulkState['status'], [BulkCleanService::STATUS_RUNNING, BulkCleanService::STATUS_PAUSED, BulkCleanService::STATUS_DONE], true) ? 'block' : 'none' ?>;">
    <div class="progress mb-2" style="height: 8px;">
        <div class="progress-bar" id="metadata-cleaner-bulk-bar" role="progressbar" style="width: <?= $bulkPercent ?>%"></div>
    </div>
    <div class="d-flex flex-wrap gap-3 small">
        <span><?= Yii::t('MetadataCleanerModule.base', 'Processed') ?>: <strong id="metadata-cleaner-bulk-processed">0</strong></span>
        <span><?= Yii::t('MetadataCleanerModule.base', 'Cleaned') ?>: <strong id="metadata-cleaner-bulk-cleaned">0</strong></span>
        <span><?= Yii::t('MetadataCleanerModule.base', 'Unchanged') ?>: <strong id="metadata-cleaner-bulk-unchanged">0</strong></span>
        <span><?= Yii::t('MetadataCleanerModule.base', 'Skipped') ?>: <strong id="metadata-cleaner-bulk-skipped">0</strong></span>
        <span><?= Yii::t('MetadataCleanerModule.base', 'Failed') ?>: <strong id="metadata-cleaner-bulk-failed">0</strong></span>
        <span class="text-body-secondary" id="metadata-cleaner-bulk-current"></span>
    </div>
</div>
