<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\models;

use yii\data\ActiveDataProvider;

/**
 * Filters for the log list on the admin page.
 */
class MetadataLogSearch extends MetadataLog
{
    public function rules()
    {
        return [
            [['file_name', 'status', 'format', 'source'], 'safe'],
        ];
    }

    public function scenarios()
    {
        return \yii\base\Model::scenarios();
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = MetadataLog::find()->with('createdBy');
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'created_at', 'file_name', 'status', 'format', 'size_before']],
        ]);

        if (!$this->load($params) || !$this->validate()) {
            return $dataProvider;
        }
        $query->andFilterWhere(['status' => $this->status])
            ->andFilterWhere(['format' => $this->format])
            ->andFilterWhere(['source' => $this->source])
            ->andFilterWhere(['like', 'file_name', $this->file_name]);

        return $dataProvider;
    }
}
