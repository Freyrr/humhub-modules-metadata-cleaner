<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\helpers\Html;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\widgets\form\ActiveForm;
use humhub\widgets\GridView;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;

/* @var $this View */
/* @var $model ConfigureForm */
/* @var $searchModel MetadataLogSearch */
/* @var $dataProvider ActiveDataProvider */
/* @var $stats array */
/* @var $cleanup array parameters of _cleanup-status */

$formatSize = static function ($before, $after) {
    if ($before === null && $after === null) {
        return '';
    }
    $max = max($before ?? 0, $after ?? 0);
    $units = ['B', 'kB', 'MB', 'GB', 'TB'];
    $unit = 0;
    $value = $max;
    while ($value >= 1000 && $unit < count($units) - 1) {
        $value /= 1000;
        $unit++;
    }
    $fmt = static fn($bytes) => $bytes === null
        ? '—'
        : Yii::$app->formatter->asDecimal($bytes / (1000 ** $unit), $unit === 0 ? 0 : 1);
    return $fmt($before) . ' → ' . $fmt($after) . ' ' . $units[$unit];
};
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Metadata</strong> Cleaner') ?>
    </div>
    <div class="panel-body">
        <p class="text-body-secondary">
            <?= Yii::t('MetadataCleanerModule.base', 'Metadata (EXIF, XMP, IPTC, GPS, author, device and editing data) is removed from uploaded images, videos, audio files and documents, without re-encoding the file.') ?>
        </p>

        <?php $form = ActiveForm::begin(['acknowledge' => true]) ?>
        <?= $form->field($model, 'formats')->checkboxList(ConfigureForm::formatOptions()) ?>
        <?= $form->field($model, 'logMode')->dropDownList(ConfigureForm::logModeOptions()) ?>
        <div class="mb-3">
            <a class="text-decoration-none" data-bs-toggle="collapse" href="#metadata-cleaner-retention" role="button" aria-expanded="false" aria-controls="metadata-cleaner-retention">
                <i class="fa fa-chevron-down"></i> <?= Yii::t('MetadataCleanerModule.base', 'Data retention rules') ?>
            </a>
            <div class="collapse" id="metadata-cleaner-retention">
                <?= $form->field($model, 'logRetentionDays')->input('number', ['min' => 1, 'max' => 3650]) ?>
                <?= $form->field($model, 'statsRetentionMonths')->input('number', ['min' => 1, 'max' => 120]) ?>
            </div>
        </div>
        <?= $form->field($model, 'keepOrientation')->checkbox() ?>
        <?= $form->field($model, 'onError')->dropDownList(ConfigureForm::onErrorOptions()) ?>
        <?= Html::saveButton() ?>
        <?php ActiveForm::end() ?>
    </div>
</div>

<?php
// Open while a cleanup exists, or when the formats changed since the last one.
$cleanupOpen = $cleanup['current'] !== null
    || ($cleanup['lastCompleted'] !== null && !$cleanup['lastCompleted']->hasFormats($cleanup['formats']));
$this->registerJs(sprintf(<<<'JS'
(function () {
    var url = %s;
    function refresh() {
        var box = document.getElementById('metadata-cleaner-cleanup-status');
        if (!box || box.getAttribute('data-running') !== '1') {
            return;
        }
        fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html !== null) {
                    box.outerHTML = html;
                }
            })
            .catch(function () {})
            .finally(function () { setTimeout(refresh, 5000); });
    }
    setTimeout(refresh, 5000);
})();
JS, json_encode(Url::to(['cleanup-status']))));
?>
<div class="panel panel-default" id="metadata-cleaner-cleanup">
    <div class="panel-heading">
        <a class="text-decoration-none<?= $cleanupOpen ? '' : ' collapsed' ?>" data-bs-toggle="collapse" href="#metadata-cleaner-cleanup-body" role="button" aria-expanded="<?= $cleanupOpen ? 'true' : 'false' ?>" aria-controls="metadata-cleaner-cleanup-body">
            <i class="fa fa-chevron-down"></i> <?= Yii::t('MetadataCleanerModule.base', '<strong>Clean existing files</strong>') ?>
        </a>
    </div>
    <div class="collapse<?= $cleanupOpen ? ' show' : '' ?>" id="metadata-cleaner-cleanup-body">
        <div class="panel-body">
            <p class="text-body-secondary">
                <?= Yii::t('MetadataCleanerModule.base', 'New uploads are cleaned automatically. This removes the metadata from the files stored before, for example before the module was enabled. It runs in the background in batches through the HumHub queue (the cron jobs must be configured): you can leave this page, pause and resume it. A file that cannot be cleaned is kept unchanged and counted as an error, and the cleanup goes on.') ?>
            </p>
            <div class="alert alert-info">
                <?= Yii::t('MetadataCleanerModule.base', '<strong>Only the formats selected in the settings above are cleaned</strong> (as saved: save the settings before starting). Files of the other formats are not changed and are counted as skipped: for example, if Office / OpenDocument is not selected, Office files are not cleaned. If you change the selected formats, the cleanup restarts from the first file.') ?>
            </div>
            <?= $this->render('_cleanup-status', $cleanup) ?>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Log</strong>') ?>
    </div>
    <div class="panel-body">
        <p class="text-body-secondary">
            <?= Yii::t('MetadataCleanerModule.base', 'Shows the 20 most recent entries (use the filters to search older ones). Entries older than the configured retention are deleted automatically every day.') ?>
        </p>
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'filterModel' => $searchModel,
            'tableOptions' => ['class' => 'table table-hover'],
            'columns' => [
                [
                    'attribute' => 'created_at',
                    'format' => 'datetime',
                    'filter' => false,
                    'headerOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'contentOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                ],
                [
                    'attribute' => 'file_name',
                    'format' => 'raw',
                    'value' => fn(MetadataLog $log) => Html::a(Html::encode($log->file_name), ['view', 'id' => $log->id]),
                    'headerOptions' => ['style' => 'width: 100%;'],
                    'contentOptions' => ['style' => 'max-width: 0; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'],
                ],
                [
                    'attribute' => 'created_by',
                    'filter' => false,
                    'value' => fn(MetadataLog $log) => $log->createdBy?->displayName ?? '',
                    'headerOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'contentOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                ],
                [
                    'attribute' => 'format',
                    'filter' => ConfigureForm::formatOptions(),
                    'headerOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'contentOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                ],
                [
                    'attribute' => 'status',
                    'filter' => MetadataLog::statusLabels(),
                    'value' => fn(MetadataLog $log) => MetadataLog::statusLabels()[$log->status] ?? $log->status,
                    'headerOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'contentOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                ],
                [
                    'attribute' => 'size_before',
                    'label' => Yii::t('MetadataCleanerModule.base', 'Dim.'),
                    'filter' => false,
                    'headerOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'contentOptions' => ['style' => 'width: 1%; white-space: nowrap;'],
                    'value' => fn(MetadataLog $log) => $formatSize($log->size_before, $log->size_after),
                ],
            ],
        ]) ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Statistics</strong>') ?>
    </div>
    <div class="panel-body">
        <p class="text-body-secondary">
            <?= Yii::t('MetadataCleanerModule.base', 'Counts every file processed by the module: metadata removed, files already clean and errors. Figures cover only the configured retention period and are independent of the log.') ?>
        </p>
        <div class="row">
            <div class="col-md-4">
                <h4><?= Yii::t('MetadataCleanerModule.base', 'By status') ?></h4>
                <table class="table table-condensed">
                    <tbody>
                    <?php foreach (MetadataStats::statusLabels() as $status => $label) : ?>
                        <tr>
                            <td><?= Html::encode($label) ?></td>
                            <td class="text-end"><?= (int)($stats['byStatus'][$status] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong><?= Yii::t('MetadataCleanerModule.base', 'Total') ?></strong></td>
                        <td class="text-end"><strong><?= (int)$stats['total'] ?></strong></td>
                    </tr>
                    <tr>
                        <td><?= Yii::t('MetadataCleanerModule.base', 'Space saved') ?></td>
                        <td class="text-end"><?= Yii::$app->formatter->asShortSize($stats['bytesSaved']) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-4">
                <h4><?= Yii::t('MetadataCleanerModule.base', 'By format') ?></h4>
                <table class="table table-condensed">
                    <thead>
                    <tr>
                        <th><?= Yii::t('MetadataCleanerModule.base', 'Format') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Total') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Removed') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'No metadata') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Errors') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats['byFormat'] as $format => $row) : ?>
                        <tr>
                            <td><?= Html::encode($format === '' ? '—' : $format) ?></td>
                            <td class="text-end"><?= $row['total'] ?></td>
                            <td class="text-end"><?= $row['cleaned'] ?></td>
                            <td class="text-end"><?= $row['clean'] ?></td>
                            <td class="text-end"><?= $row['errors'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($stats['byFormat'] === []) : ?>
                        <tr><td colspan="5" class="text-body-secondary">—</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="col-md-4">
                <h4><?= Yii::t('MetadataCleanerModule.base', 'Last 6 months') ?></h4>
                <table class="table table-condensed">
                    <thead>
                    <tr>
                        <th><?= Yii::t('MetadataCleanerModule.base', 'Month') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Total') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Removed') ?></th>
                        <th class="text-end"><?= Yii::t('MetadataCleanerModule.base', 'Errors') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats['byMonth'] as $row) : ?>
                        <tr>
                            <td><?= Html::encode($row['month']) ?></td>
                            <td class="text-end"><?= $row['total'] ?></td>
                            <td class="text-end"><?= $row['cleaned'] ?></td>
                            <td class="text-end"><?= $row['errors'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
