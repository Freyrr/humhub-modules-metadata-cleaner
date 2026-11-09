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
            'pagination' => false,
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'created_at', 'file_name', 'status', 'format', 'size_before']],
        ]);

        if ($this->load($params) && $this->validate()) {
            $query->andFilterWhere(['status' => $this->status])
                ->andFilterWhere(['format' => $this->format])
                ->andFilterWhere(['source' => $this->source])
                ->andFilterWhere(['like', 'file_name', $this->file_name]);
        }

        // Cap at 20 rows after filters; the provider's sort still applies
        // its ORDER BY on top, and LIMIT always runs last in SQL anyway.
        $query->limit(20);

        return $dataProvider;
    }
}
