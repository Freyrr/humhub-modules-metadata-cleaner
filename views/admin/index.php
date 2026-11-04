<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\helpers\Html;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\widgets\form\ActiveForm;
use humhub\widgets\GridView;
use yii\data\ActiveDataProvider;

/* @var $this View */
/* @var $model ConfigureForm */
/* @var $searchModel MetadataLogSearch */
/* @var $dataProvider ActiveDataProvider */

$formatSize = static fn($bytes) => $bytes === null ? '' : Yii::$app->formatter->asShortSize($bytes, 1);
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Metadata</strong> Cleaner') ?>
    </div>
    <div class="panel-body">
        <p class="text-body-secondary">
            <?= Yii::t('MetadataCleanerModule.base', 'EXIF, XMP and IPTC metadata (GPS included) are removed from every uploaded image and video, without re-encoding the file.') ?>
        </p>

        <?php $form = ActiveForm::begin(['acknowledge' => true]) ?>
        <?= $form->field($model, 'formats')->checkboxList(ConfigureForm::formatOptions()) ?>
        <?= $form->field($model, 'logMode')->dropDownList(ConfigureForm::logModeOptions()) ?>
        <?= $form->field($model, 'logRetentionDays')->input('number', ['min' => 1, 'max' => 3650]) ?>
        <?= $form->field($model, 'keepOrientation')->checkbox() ?>
        <?= $form->field($model, 'onError')->dropDownList(ConfigureForm::onErrorOptions()) ?>
        <?= Html::saveButton() ?>
        <?php ActiveForm::end() ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('MetadataCleanerModule.base', '<strong>Log</strong>') ?>
    </div>
    <div class="panel-body">
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'filterModel' => $searchModel,
            'tableOptions' => ['class' => 'table table-hover'],
            'columns' => [
                [
                    'attribute' => 'created_at',
                    'format' => 'datetime',
                    'filter' => false,
                ],
                [
                    'attribute' => 'file_name',
                    'format' => 'raw',
                    'value' => fn(MetadataLog $log) => Html::a(Html::encode($log->file_name), ['view', 'id' => $log->id]),
                ],
                [
                    'attribute' => 'created_by',
                    'filter' => false,
                    'value' => fn(MetadataLog $log) => $log->createdBy?->displayName ?? '',
                ],
                [
                    'attribute' => 'format',
                    'filter' => ['jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WebP', 'gif' => 'GIF', 'heif' => 'HEIF', 'video' => 'Video', 'tiff' => 'TIFF'],
                ],
                [
                    'attribute' => 'status',
                    'filter' => MetadataLog::statusLabels(),
                    'value' => fn(MetadataLog $log) => MetadataLog::statusLabels()[$log->status] ?? $log->status,
                ],
                [
                    'attribute' => 'metadata',
                    'filter' => false,
                    'value' => fn(MetadataLog $log) => $log->error ?: Yii::t('MetadataCleanerModule.base', '{count} fields', ['count' => count($log->getMetadataMap())]),
                ],
                [
                    'attribute' => 'size_before',
                    'filter' => false,
                    'value' => fn(MetadataLog $log) => $formatSize($log->size_before) . ' → ' . $formatSize($log->size_after),
                ],
            ],
        ]) ?>
    </div>
</div>
