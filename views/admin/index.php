<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\modules\metadataCleaner\assets\BulkCleanAsset;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\widgets\bootstrap\Tabs;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;

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
BulkCleanAsset::register($this);
$this->registerJsConfig('metadataCleaner.bulk', [
    'controlUrl' => Url::to(['bulk-clean']),
    'stepUrl' => Url::to(['bulk-clean-step']),
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
    'confirm' => [
        'header' => Yii::t('MetadataCleanerModule.base', '<strong>Clean</strong> existing files'),
        'body' => Yii::t('MetadataCleanerModule.base', 'The job rewrites every stored file of the selected types and removes its metadata. This cannot be undone. Make a backup of the file storage before you start, and run a dry run first to see what would change.'),
        'confirm' => Yii::t('MetadataCleanerModule.base', 'Start cleaning'),
        'cancel' => Yii::t('MetadataCleanerModule.base', 'Cancel'),
    ],
]);
