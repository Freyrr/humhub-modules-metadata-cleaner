<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\helpers\Html;
use humhub\modules\metadataCleaner\models\CleanRun;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\widgets\bootstrap\Button;

/* @var $current CleanRun|null */
/* @var $lastCompleted CleanRun|null */
/* @var $formats string[] formats selected in the settings */

$t = static fn(string $message, array $params = []) => Yii::t('MetadataCleanerModule.base', $message, $params);
$formatLabels = static function (array $list): string {
    $options = ConfigureForm::formatOptions();
    $labels = [];
    foreach ($list as $format) {
        $label = $options[$format] ?? null;
        if ($label !== null) {
            $labels[] = trim(preg_replace('/\s*\(.*$/', '', $label));
        }
    }

    return $labels === [] ? '—' : implode(', ', $labels);
};
$counters = static function (CleanRun $run) use ($t): string {
    $rows = [
        [$t('Metadata removed'), $run->cleaned, ''],
        [$t('Already without metadata'), $run->unchanged, ''],
        [$t('Skipped (format not selected or not handled, content missing)'), $run->skipped, ''],
        [$t('Errors (file kept unchanged)'), $run->failed, $run->failed > 0 ? 'text-danger' : ''],
    ];
    $html = '<table class="table table-condensed mb-2"><tbody>';
    foreach ($rows as [$label, $value, $class]) {
        $html .= '<tr><td>' . Html::encode($label) . '</td><td class="text-end ' . $class . '">'
            . Yii::$app->formatter->asInteger($value) . '</td></tr>';
    }
    $html .= '<tr><td>' . Html::encode($t('Space saved')) . '</td><td class="text-end">'
        . Yii::$app->formatter->asShortSize($run->bytes_saved) . '</td></tr>';

    return $html . '</tbody></table>';
};
// Plain POST forms: a full page load after each action, no pjax.
$post = static function (string $label, string $action, bool $primary, ?string $confirm = null) use ($t): string {
    $button = ($primary ? Button::primary($label) : Button::light($label))
        ->submit()
        ->sm();
    if ($confirm !== null) {
        $button->confirm($t('Clean existing files'), $confirm, $t('Start'));
    }

    return Html::beginForm([$action], 'post', ['class' => 'd-inline', 'data-pjax' => '0'])
        . $button
        . Html::endForm();
};
?>
<div id="metadata-cleaner-cleanup-status" data-running="<?= $current !== null && $current->isRunning() ? '1' : '0' ?>">
    <?php if ($current !== null) : ?>
        <h4>
            <?= $t('Current cleanup') ?>
            <span class="badge <?= $current->isRunning() ? 'bg-info' : 'bg-warning' ?>">
                <?= Html::encode(CleanRun::statusLabels()[$current->status] ?? $current->status) ?>
            </span>
        </h4>
        <?php if ($current->isStalled()) : ?>
            <div class="alert alert-warning">
                <?= $t('No progress since {time}. The HumHub queue may not be running: check the cron jobs in Administration → Information → Background jobs, then click "Resume".', [
                    'time' => Yii::$app->formatter->asDatetime($current->updated_at, 'short'),
                ]) ?>
            </div>
        <?php endif; ?>
        <div class="progress mb-2" role="progressbar" aria-valuenow="<?= $current->getPercent() ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar<?= $current->isRunning() ? ' progress-bar-striped progress-bar-animated' : '' ?>" style="width: <?= $current->getPercent() ?>%">
                <?= $current->getPercent() ?>%
            </div>
        </div>
        <p>
            <?= $t('{processed} of {total} files processed.', [
                'processed' => Yii::$app->formatter->asInteger($current->processed),
                'total' => Yii::$app->formatter->asInteger($current->total),
            ]) ?>
            <?= $t('Started {date} by {user}, last progress {time}.', [
                'date' => Yii::$app->formatter->asDatetime($current->started_at, 'short'),
                'user' => Html::encode($current->createdBy?->displayName ?? '—'),
                'time' => Yii::$app->formatter->asDatetime($current->updated_at, 'short'),
            ]) ?>
            <br><span class="text-body-secondary"><?= $t('Formats: {formats}', ['formats' => Html::encode($formatLabels($current->getFormatList()))]) ?></span>
        </p>
        <?= $counters($current) ?>
        <?php if ($current->last_error !== null) : ?>
            <p class="text-danger small"><?= $t('Last error: {error}', ['error' => Html::encode($current->last_error)]) ?></p>
        <?php endif; ?>
        <?php $formatsChanged = !$current->hasFormats($formats); ?>
        <?php if ($formatsChanged) : ?>
            <p class="text-warning"><?= $t('The selected formats have changed since this cleanup started: it will restart from the first file.') ?></p>
        <?php endif; ?>
        <p>
            <?php if ($current->isRunning() && !$current->isStalled()) : ?>
                <?= $post($t('Pause'), 'cleanup-stop', false) ?>
            <?php elseif ($formatsChanged) : ?>
                <?= $post($t('Start'), 'cleanup-start', true, $t('Start a new cleanup of all stored files with the formats selected above?')) ?>
            <?php else : ?>
                <?= $post($t('Resume'), 'cleanup-resume', true) ?>
            <?php endif; ?>
        </p>
    <?php else : ?>
        <p>
            <?= $post($t('Start'), 'cleanup-start', true, $t('Start a new cleanup of all stored files with the formats selected above?')) ?>
        </p>
    <?php endif; ?>

    <h4><?= $t('Last completed cleanup') ?></h4>
    <?php if ($lastCompleted === null) : ?>
        <p class="text-body-secondary"><?= $t('No cleanup completed yet.') ?></p>
    <?php else : ?>
        <p>
            <?= $t('From {start} to {end}: {total} files processed.', [
                'start' => Yii::$app->formatter->asDatetime($lastCompleted->started_at, 'short'),
                'end' => Yii::$app->formatter->asDatetime($lastCompleted->finished_at ?? $lastCompleted->updated_at, 'short'),
                'total' => Yii::$app->formatter->asInteger($lastCompleted->processed),
            ]) ?>
            <?php if ($lastCompleted->failed === 0) : ?>
                <span class="text-success"><i class="fa fa-check"></i> <?= $t('All files processed without errors.') ?></span>
            <?php else : ?>
                <span class="text-danger"><?= $t('{count} files could not be cleaned and were kept unchanged (details in the log below, if enabled).', ['count' => $lastCompleted->failed]) ?></span>
            <?php endif; ?>
            <br><span class="text-body-secondary"><?= $t('Formats: {formats}', ['formats' => Html::encode($formatLabels($lastCompleted->getFormatList()))]) ?></span>
        </p>
        <?= $counters($lastCompleted) ?>
    <?php endif; ?>
</div>
