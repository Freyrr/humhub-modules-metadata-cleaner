<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\controllers;

use humhub\components\access\ControllerAccess;
use humhub\modules\admin\components\Controller;
use humhub\modules\metadataCleaner\models\CleanRun;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\CleanupService;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * Settings and log page. System administrators only: the log can hold
 * personal data such as GPS positions.
 *
 * @property Module $module
 */
class AdminController extends Controller
{
    /**
     * @inheritdoc
     */
    protected function getAccessRules()
    {
        return [
            [ControllerAccess::RULE_ADMIN_ONLY],
        ];
    }

    public function actionIndex()
    {
        $form = new ConfigureForm($this->module);
        if ($form->load(Yii::$app->request->post()) && $form->save()) {
            (new CleanupService($this->module))->onSettingsSaved();
            $this->view->saved();
            return $this->redirect(['index']);
        }

        $searchModel = new MetadataLogSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->get());

        return $this->render('index', [
            'model' => $form,
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'stats' => MetadataStats::summary(),
            'cleanup' => $this->cleanupParams(),
        ]);
    }

    /**
     * Starts the cleanup of the files stored so far, with the saved formats.
     */
    public function actionCleanupStart()
    {
        $this->forcePostRequest();
        (new CleanupService($this->module))->start();

        return $this->redirect(['index', '#' => 'metadata-cleaner-cleanup']);
    }

    public function actionCleanupStop()
    {
        $this->forcePostRequest();
        $run = CleanRun::findCurrent();
        if ($run !== null) {
            (new CleanupService($this->module))->stop($run);
        }

        return $this->redirect(['index', '#' => 'metadata-cleaner-cleanup']);
    }

    /**
     * Continues a paused or stalled cleanup from the last file processed,
     * or from the first file when the selected formats changed.
     */
    public function actionCleanupResume()
    {
        $this->forcePostRequest();
        $run = CleanRun::findCurrent();
        if ($run !== null) {
            (new CleanupService($this->module))->resume($run);
        }

        return $this->redirect(['index', '#' => 'metadata-cleaner-cleanup']);
    }

    /**
     * Progress report, reloaded by the admin page while a cleanup runs.
     */
    public function actionCleanupStatus()
    {
        return $this->renderPartial('_cleanup-status', $this->cleanupParams());
    }

    private function cleanupParams(): array
    {
        return [
            'current' => CleanRun::findCurrent(),
            'lastCompleted' => CleanRun::findLastCompleted(),
            'formats' => $this->module->getEnabledFormats(),
        ];
    }

    public function actionView($id)
    {
        $log = MetadataLog::findOne(['id' => (int)$id]);
        if ($log === null) {
            throw new NotFoundHttpException();
        }

        return $this->render('view', ['log' => $log]);
    }
}
