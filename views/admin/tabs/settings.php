<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\View;
use humhub\helpers\Html;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\widgets\form\ActiveForm;

/* @var $this View */
/* @var $model ConfigureForm */
?>
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
