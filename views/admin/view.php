<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\helpers\Html;
use humhub\modules\metadataCleaner\models\MetadataLog;

/* @var $this View */
/* @var $log MetadataLog */

$formatter = Yii::$app->formatter;
$metadata = $log->getMetadataMap();
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <?= Html::a(Yii::t('MetadataCleanerModule.base', 'Back to the log'), ['index'], ['class' => 'float-end']) ?>
        <strong><?= Html::encode($log->file_name) ?></strong>
    </div>
    <div class="panel-body">
        <table class="table table-sm">
            <tr><th><?= $log->getAttributeLabel('created_at') ?></th><td><?= $formatter->asDatetime($log->created_at) ?></td></tr>
            <tr><th><?= $log->getAttributeLabel('created_by') ?></th><td><?= Html::encode($log->createdBy?->displayName ?? '') ?></td></tr>
            <tr><th><?= $log->getAttributeLabel('status') ?></th><td><?= Html::encode(MetadataLog::statusLabels()[$log->status] ?? $log->status) ?></td></tr>
            <?php if ($log->error): ?>
                <tr><th><?= $log->getAttributeLabel('error') ?></th><td><?= Html::encode($log->error) ?></td></tr>
            <?php endif ?>
            <tr><th><?= $log->getAttributeLabel('format') ?></th><td><?= Html::encode((string)$log->format) ?></td></tr>
            <tr><th><?= $log->getAttributeLabel('mime_type') ?></th><td><?= Html::encode((string)$log->mime_type) ?></td></tr>
            <tr><th><?= $log->getAttributeLabel('size_before') ?></th><td><?= $log->size_before === null ? '' : $formatter->asShortSize($log->size_before, 1) ?></td></tr>
            <tr><th><?= $log->getAttributeLabel('size_after') ?></th><td><?= $log->size_after === null ? '' : $formatter->asShortSize($log->size_after, 1) ?></td></tr>
        </table>

        <?php if ($metadata !== []): ?>
            <h5><?= $log->getAttributeLabel('metadata') ?></h5>
            <table class="table table-sm table-striped">
                <?php foreach ($metadata as $name => $value): ?>
                    <tr><td><code><?= Html::encode($name) ?></code></td><td><?= Html::encode($value) ?></td></tr>
                <?php endforeach ?>
            </table>
        <?php endif ?>
    </div>
</div>
