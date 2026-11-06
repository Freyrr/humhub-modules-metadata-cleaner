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
        ]);
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
