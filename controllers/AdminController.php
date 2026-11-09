<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\controllers;

use humhub\components\access\ControllerAccess;
use humhub\modules\admin\components\Controller;
use humhub\modules\metadataCleaner\models\ConfigureForm;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataLogSearch;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\BulkCleanService;
use Yii;
use yii\web\BadRequestHttpException;
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
            'bulkState' => (new BulkCleanService($this->module))->state(),
        ]);
    }

    /**
     * Controls the retroactive cleaning job: op = start|pause|resume|stop.
     */
    public function actionBulkClean()
    {
        $this->requirePost();

        $service = new BulkCleanService($this->module);
        $state = match (Yii::$app->request->post('op')) {
            'start' => $service->start((bool)Yii::$app->request->post('dryRun', false)),
            'pause' => $service->pause(),
            'resume' => $service->resume(),
            'stop' => $service->stop(),
            default => throw new BadRequestHttpException('Unknown operation.'),
        };

        return $this->asJson(['state' => $state]);
    }

    /**
     * Processes one chunk of files for the running job. Called repeatedly
     * by the admin page until the job is done.
     */
    public function actionBulkCleanStep()
    {
        $this->requirePost();

        return $this->asJson(['state' => (new BulkCleanService($this->module))->process()]);
    }

    private function requirePost(): void
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException('POST required.');
        }
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
