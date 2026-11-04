<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\models;

use humhub\components\ActiveRecord;
use humhub\modules\file\models\File;
use humhub\modules\user\models\User;

/**
 * One row per file that had metadata removed.
 *
 * @property int $id
 * @property int|null $file_id
 * @property string|null $file_guid
 * @property string|null $file_name
 * @property string $status one of the STATUS_* constants
 * @property string|null $error why cleaning failed
 * @property string|null $format
 * @property string $source
 * @property string|null $fields JSON list of field names
 * @property string|null $field_values JSON map of field name => value
 * @property int|null $created_by
 * @property string $created_at upload date
 *
 * @property-read File|null $file
 * @property-read User|null $createdBy
 */
class MetadataLog extends ActiveRecord
{
    /** Metadata removed. */
    public const STATUS_CLEANED = 'cleaned';
    /** Cleaning failed, the file was passed to HumHub unchanged. */
    public const STATUS_PASSED = 'passed';
    /** Cleaning failed, the file was rejected. */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'metadata_cleaner_log';
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        // created_at/created_by are set explicitly by LogService.
        return \yii\db\ActiveRecord::beforeSave($insert);
    }

    public function getFile()
    {
        return $this->hasOne(File::class, ['id' => 'file_id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    /**
     * @return string[]
     */
    public function getFieldList(): array
    {
        $fields = json_decode((string)$this->fields, true);

        return is_array($fields) ? $fields : [];
    }

    /**
     * @return array<string, string>
     */
    public function getValueMap(): array
    {
        $values = json_decode((string)$this->field_values, true);

        return is_array($values) ? $values : [];
    }
}
