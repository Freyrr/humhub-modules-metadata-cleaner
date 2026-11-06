<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\Migration;

/**
 * Creates the table of the cleanups of already stored files: one row per
 * run, with its progress and totals.
 */
class m261106_000000_clean_run extends Migration
{
    public function safeUp()
    {
        $this->safeCreateTable('metadata_cleaner_run', [
            'id' => $this->primaryKey(),
            'status' => $this->string(16)->notNull(),
            'token' => $this->string(32)->notNull(),
            'formats' => $this->text()->null(),
            'last_file_id' => $this->integer()->notNull()->defaultValue(0),
            'max_file_id' => $this->integer()->notNull()->defaultValue(0),
            'total' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'processed' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'cleaned' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'unchanged' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'skipped' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'failed' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'bytes_saved' => $this->bigInteger()->notNull()->defaultValue(0),
            'last_error' => $this->string(255)->null(),
            'created_by' => $this->integer()->null(),
            'started_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
            'finished_at' => $this->dateTime()->null(),
        ]);
        $this->safeCreateIndex('idx_metadata_cleaner_run_status', 'metadata_cleaner_run', 'status');
    }

    public function safeDown()
    {
        $this->safeDropTable('metadata_cleaner_run');
    }
}
