<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\Migration;

/**
 * Creates the permanent statistics table: monthly counters per format and
 * outcome. Unlike the log, it is never purged, so monthly statistics survive
 * the log retention period.
 */
class m261105_000000_stats extends Migration
{
    public function safeUp()
    {
        $this->safeCreateTable('metadata_cleaner_stats', [
            'id' => $this->primaryKey(),
            'year_month' => $this->string(7)->notNull(),
            'format' => $this->string(16)->notNull()->defaultValue(''),
            'status' => $this->string(16)->notNull(),
            'total' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'bytes_saved' => $this->bigInteger()->notNull()->defaultValue(0),
        ]);
        $this->safeCreateIndex(
            'idx_metadata_cleaner_stats_bucket',
            'metadata_cleaner_stats',
            ['year_month', 'format', 'status'],
            true,
        );
    }

    public function safeDown()
    {
        $this->safeDropTable('metadata_cleaner_stats');
    }
}
