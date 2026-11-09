<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\services\BulkCleanService;
use humhub\widgets\bootstrap\Tabs;
use yii\data\ActiveDataProvider;

/* @var $this View */
/* @var $model ConfigureForm */
/* @var $searchModel MetadataLogSearch */
/* @var $dataProvider ActiveDataProvider */
/* @var $stats array */
/* @var $bulkState array */

Yii::setAlias('@metadataCleanerTabs', $this->context->module->getViewPath() . '/admin/tabs');
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Metadata</strong> Cleaner') ?>
    </div>
    <div class="panel-body">
        <p class="text-body-secondary">
            <?= Yii::t('MetadataCleanerModule.base', 'Metadata (EXIF, XMP, IPTC, GPS, author, device and editing data) is removed from uploaded images, videos, audio files and documents, without re-encoding the file.') ?>
        </p>

        <?= Tabs::widget([
            'viewPath' => '@metadataCleanerTabs',
            'items' => [
                [
                    'label' => Yii::t('MetadataCleanerModule.base', 'Settings'),
                    'view' => 'settings',
                    'active' => true,
                ],
                [
                    'label' => Yii::t('MetadataCleanerModule.base', 'Existing files'),
                    'view' => 'bulk',
                    'linkOptions' => ['id' => 'metadata-cleaner-tab-bulk'],
                ],
                [
                    'label' => Yii::t('MetadataCleanerModule.base', 'Activity'),
                    'view' => 'activity',
                ],
            ],
            'params' => [
                'model' => $model,
                'searchModel' => $searchModel,
                'dataProvider' => $dataProvider,
                'stats' => $stats,
                'bulkState' => $bulkState,
            ],
        ]) ?>
    </div>
</div>
<?php
$bulkConfig = [
    'controlUrl' => \yii\helpers\Url::to(['bulk-clean']),
    'stepUrl' => \yii\helpers\Url::to(['bulk-clean-step']),
    'state' => $bulkState,
    'tabBulkLabel' => Yii::t('MetadataCleanerModule.base', 'Existing files'),
    'labels' => [
        'idle' => Yii::t('MetadataCleanerModule.base', 'Ready'),
        'running' => Yii::t('MetadataCleanerModule.base', 'Running'),
        'paused' => Yii::t('MetadataCleanerModule.base', 'Paused'),
        'done' => Yii::t('MetadataCleanerModule.base', 'Finished'),
        'dryRunSuffix' => Yii::t('MetadataCleanerModule.base', '(dry run)'),
        'currentFile' => Yii::t('MetadataCleanerModule.base', 'Current file: {name}'),
    ],
];
$bulkConfigJson = \yii\helpers\Json::encode($bulkConfig);
$this->registerJs(<<<JS
(function () {
    var client = humhub.require('client');
    var \$ = window.jQuery;
    var cfg = {$bulkConfigJson};
    var state = cfg.state;

    function render() {
        var s = state.status;
        var active = s === 'running' || s === 'paused' || s === 'done';
        \$('#metadata-cleaner-bulk-start').prop('disabled', s === 'running' || s === 'paused').toggle(s === 'idle' || s === 'done');
        \$('#metadata-cleaner-bulk-pause').toggle(s === 'running');
        \$('#metadata-cleaner-bulk-resume').toggle(s === 'paused');
        \$('#metadata-cleaner-bulk-stop').toggle(s === 'running' || s === 'paused' || s === 'done');
        \$('#metadata-cleaner-bulk-dryrun').prop('disabled', s === 'running' || s === 'paused');
        \$('#metadata-cleaner-bulk-progress').toggle(active);

        var label = (cfg.labels[s] || s) + (state.dryRun && s !== 'idle' ? ' ' + cfg.labels.dryRunSuffix : '');
        \$('#metadata-cleaner-bulk-status').text(label);
        \$('#metadata-cleaner-tab-bulk').text(
            cfg.tabBulkLabel + ((s === 'running' || s === 'paused') ? ' (' + label + ')' : '')
        );

        var pct = state.total > 0 ? Math.min(100, Math.round(state.processed / state.total * 100)) : 0;
        \$('#metadata-cleaner-bulk-bar').css('width', pct + '%');
        \$('#metadata-cleaner-bulk-processed').text(state.processed + ' / ' + state.total);
        \$('#metadata-cleaner-bulk-cleaned').text(state.cleaned);
        \$('#metadata-cleaner-bulk-unchanged').text(state.unchanged);
        \$('#metadata-cleaner-bulk-skipped').text(state.skipped);
        \$('#metadata-cleaner-bulk-failed').text(state.failed);
        \$('#metadata-cleaner-bulk-current').text(
            state.currentFile ? cfg.labels.currentFile.replace('{name}', state.currentFile) : ''
        );
    }

    function control(op, extra) {
        var data = {op: op};
        if (extra) { \$.extend(data, extra); }
        return client.post(cfg.controlUrl, {data: data}).then(function (res) {
            state = res.state;
            render();
            return state;
        });
    }

    function step() {
        if (state.status !== 'running') {
            render();
            return;
        }
        client.post(cfg.stepUrl, {}).then(function (res) {
            state = res.state;
            render();
            if (state.status === 'running') {
                step();
            }
        }).catch(function () {
            render();
        });
    }

    \$('#metadata-cleaner-bulk-start').on('click', function () {
        control('start', {dryRun: \$('#metadata-cleaner-bulk-dryrun').is(':checked') ? 1 : 0}).then(step);
    });
    \$('#metadata-cleaner-bulk-pause').on('click', function () {
        control('pause');
    });
    \$('#metadata-cleaner-bulk-resume').on('click', function () {
        control('resume').then(step);
    });
    \$('#metadata-cleaner-bulk-stop').on('click', function () {
        control('stop');
    });

    render();
    if (state.status === 'running') {
        step();
    }
})();
JS
);
