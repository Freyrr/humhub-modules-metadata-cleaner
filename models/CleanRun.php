<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\models;

use humhub\components\ActiveRecord;
use humhub\modules\user\models\User;
use Yii;

/**
 * One cleanup of the files stored before the module was enabled (or before
 * a format was switched on). The files are processed by queue jobs in
 * batches, in id order; last_file_id is where the next batch resumes.
 *
 * @property int $id
 * @property string $status one of the STATUS_* constants
 * @property string $token identifies the job chain allowed to work on the run
 * @property string|null $formats comma-separated formats cleaned by this run
 * @property int $last_file_id last file processed
 * @property int $max_file_id last file included in the run (files stored later are cleaned on upload)
 * @property int $total files to process
 * @property int $processed files processed so far
 * @property int $cleaned files whose metadata was removed
 * @property int $unchanged files of a cleaned format that had no metadata
 * @property int $skipped files not cleaned: format not selected or not handled, content missing
 * @property int $failed files that could not be cleaned (kept unchanged)
 * @property int $bytes_saved
 * @property string|null $last_error
 * @property int|null $created_by
 * @property string $started_at
 * @property string $updated_at time of the last progress
 * @property string|null $finished_at
 *
 * @property-read User|null $createdBy
 */
class CleanRun extends ActiveRecord
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_COMPLETED = 'completed';
    /** Stopped and then replaced by a new cleanup. */
    public const STATUS_CANCELLED = 'cancelled';

    /** A running cleanup without progress for this long is shown as stalled. */
    public const STALLED_AFTER_SECONDS = 600;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'metadata_cleaner_run';
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        // created_by and the dates are set explicitly.
        return \yii\db\ActiveRecord::beforeSave($insert);
    }

    /**
     * The cleanup that is running or was stopped before the end, if any.
     */
    public static function findCurrent(): ?self
    {
        return self::find()
            ->where(['status' => [self::STATUS_RUNNING, self::STATUS_STOPPED]])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    public static function findLastCompleted(): ?self
    {
        return self::find()
            ->where(['status' => self::STATUS_COMPLETED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * True when the run should be progressing but no batch has been
     * processed for a while: the queue is not running (cron missing) or
     * the job was killed.
     */
    public function isStalled(): bool
    {
        return $this->isRunning() && strtotime($this->updated_at) < time() - self::STALLED_AFTER_SECONDS;
    }

    public function getPercent(): int
    {
        if ($this->status === self::STATUS_COMPLETED || $this->total <= 0) {
            return $this->status === self::STATUS_COMPLETED ? 100 : 0;
        }

        return (int)min(100, floor($this->processed * 100 / $this->total));
    }

    /**
     * @return string[]
     */
    public function getFormatList(): array
    {
        return $this->formats === null || $this->formats === '' ? [] : explode(',', $this->formats);
    }

    public static function newToken(): string
    {
        return Yii::$app->security->generateRandomString(32);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_RUNNING => Yii::t('MetadataCleanerModule.base', 'Running'),
            self::STATUS_STOPPED => Yii::t('MetadataCleanerModule.base', 'Stopped'),
            self::STATUS_COMPLETED => Yii::t('MetadataCleanerModule.base', 'Completed'),
            self::STATUS_CANCELLED => Yii::t('MetadataCleanerModule.base', 'Cancelled'),
        ];
    }

    public function getCreatedBy()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
