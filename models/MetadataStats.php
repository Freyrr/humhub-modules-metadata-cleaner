<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\models;

use humhub\components\ActiveRecord;
use Throwable;
use Yii;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * One row per (year_month, format, status) bucket. Written on every
 * processing outcome — including files that had no metadata — and kept
 * for a configurable number of months, independently of the detailed
 * log retention.
 *
 * @property int $id
 * @property string $year_month YYYY-MM
 * @property string $format detected format group, '' when unknown
 * @property string $status one of the STATUS_* constants
 * @property int $total processed files in the bucket
 * @property int $bytes_saved metadata bytes removed (cleaned rows only)
 */
class MetadataStats extends ActiveRecord
{
    /** Metadata was found and removed. */
    public const STATUS_CLEANED = 'cleaned';
    /** The file was processed and had no metadata to remove. */
    public const STATUS_CLEAN = 'clean';
    /** Cleaning failed, the file was passed unchanged. */
    public const STATUS_PASSED = 'passed';
    /** Cleaning failed, the file was rejected. */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'metadata_cleaner_stats';
    }

    /**
     * Adds one processed file to the current month's bucket.
     *
     * Never throws: statistics must not break an upload.
     */
    public static function record(?string $format, string $status, int $bytesSaved = 0): void
    {
        try {
            $format = (string)($format ?? '');
            $bytesSaved = max(0, $bytesSaved);
            $condition = [
                'year_month' => date('Y-m'),
                'format' => $format,
                'status' => $status,
            ];
            $updated = static::updateAll([
                'total' => new Expression('total + 1'),
                'bytes_saved' => new Expression('bytes_saved + ' . $bytesSaved),
            ], $condition);
            if ($updated > 0) {
                return;
            }
            try {
                $row = new static();
                $row->year_month = $condition['year_month'];
                $row->format = $format;
                $row->status = $status;
                $row->total = 1;
                $row->bytes_saved = $bytesSaved;
                $row->save(false);
            } catch (IntegrityException) {
                // Another request created the bucket first: count on it.
                static::updateAll([
                    'total' => new Expression('total + 1'),
                    'bytes_saved' => new Expression('bytes_saved + ' . $bytesSaved),
                ], $condition);
            }
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
        }
    }

    /**
     * Deletes monthly buckets older than the retention period.
     *
     * @return int number of deleted rows
     */
    public static function purgeOlderThan(int $months): int
    {
        try {
            $cutoff = (new \DateTimeImmutable('first day of this month'))
                ->modify('-' . max(1, $months) . ' months')
                ->format('Y-m');

            return static::deleteAll(['<', 'year_month', $cutoff]);
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');

            return 0;
        }
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_CLEANED => Yii::t('MetadataCleanerModule.base', 'Metadata removed'),
            self::STATUS_CLEAN => Yii::t('MetadataCleanerModule.base', 'Processed, no metadata'),
            self::STATUS_PASSED => Yii::t('MetadataCleanerModule.base', 'Error, passed unchanged'),
            self::STATUS_REJECTED => Yii::t('MetadataCleanerModule.base', 'Error, rejected'),
        ];
    }

    /**
     * Aggregated counters for the admin statistics panel, over the whole
     * history kept in the stats table.
     *
     * @return array{
     *     total: int,
     *     bytesSaved: int,
     *     byStatus: array<string, int>,
     *     byFormat: array<string, array{total: int, cleaned: int, clean: int, errors: int}>,
     *     byMonth: list<array{month: string, total: int, cleaned: int, errors: int}>
     * }
     */
    public static function summary(): array
    {
        $byStatus = [];
        $byFormat = [];
        $months = [];

        $total = 0;
        $bytesSaved = 0;
        try {
            $rows = static::find()
                ->select(['year_month', 'format', 'status', 'n' => 'SUM(total)', 'b' => 'SUM(bytes_saved)'])
                ->groupBy(['year_month', 'format', 'status'])
                ->asArray()->all();
        } catch (Throwable $e) {
            Yii::error($e, 'metadata-cleaner');
            $rows = [];
        }
        foreach ($rows as $row) {
            $n = (int)$row['n'];
            $b = (int)$row['b'];
            $status = (string)$row['status'];
            $format = (string)$row['format'];

            $total += $n;
            $bytesSaved += $b;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $n;

            $byFormat[$format] ??= ['total' => 0, 'cleaned' => 0, 'clean' => 0, 'errors' => 0];
            $byFormat[$format]['total'] += $n;
            if ($status === self::STATUS_CLEANED) {
                $byFormat[$format]['cleaned'] += $n;
            } elseif ($status === self::STATUS_CLEAN) {
                $byFormat[$format]['clean'] += $n;
            } else {
                $byFormat[$format]['errors'] += $n;
            }

            $key = (string)$row['year_month'];
            $months[$key] ??= ['month' => $key, 'total' => 0, 'cleaned' => 0, 'errors' => 0];
            $months[$key]['total'] += $n;
            if ($status === self::STATUS_CLEANED) {
                $months[$key]['cleaned'] += $n;
            } elseif ($status !== self::STATUS_CLEAN) {
                $months[$key]['errors'] += $n;
            }
        }
        krsort($months);
        $months = array_slice($months, 0, 6, true);
        uasort($byFormat, static fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total' => $total,
            'bytesSaved' => $bytesSaved,
            'byStatus' => $byStatus,
            'byFormat' => $byFormat,
            'byMonth' => array_reverse(array_values($months)),
        ];
    }
}
