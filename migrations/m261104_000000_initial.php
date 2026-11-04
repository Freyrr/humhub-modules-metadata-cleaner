<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\Migration;

/**
 * Creates the log table.
 */
class m261104_000000_initial extends Migration
{
    public function safeUp()
    {
        $this->safeCreateTable('metadata_cleaner_log', [
            'id' => $this->primaryKey(),
            'file_id' => $this->integer()->null(),
            'file_guid' => $this->string(45)->null(),
            'file_name' => $this->string(255)->null(),
            'mime_type' => $this->string(150)->null(),
            'size_before' => $this->bigInteger()->null(),
            'size_after' => $this->bigInteger()->null(),
            'status' => $this->string(16)->notNull()->defaultValue('cleaned'),
            'error' => $this->string(255)->null(),
            'format' => $this->string(16)->null(),
            'source' => $this->string(16)->notNull(),
            'metadata' => $this->text()->null(),
            'object_model' => $this->string(100)->null(),
            'object_id' => $this->integer()->null(),
            'content_id' => $this->integer()->null(),
            'contentcontainer_id' => $this->integer()->null(),
            'created_by' => $this->integer()->null(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->safeCreateIndex('idx_metadata_cleaner_log_created_at', 'metadata_cleaner_log', 'created_at');
        $this->safeCreateIndex('idx_metadata_cleaner_log_status', 'metadata_cleaner_log', 'status');
        $this->safeCreateIndex('idx_metadata_cleaner_log_file_id', 'metadata_cleaner_log', 'file_id');
        $this->safeCreateIndex('idx_metadata_cleaner_log_object', 'metadata_cleaner_log', ['object_model', 'object_id']);
        $this->safeAddForeignKey('fk_metadata_cleaner_log_file', 'metadata_cleaner_log', 'file_id', 'file', 'id', 'SET NULL', 'CASCADE');
        $this->safeAddForeignKey('fk_metadata_cleaner_log_user', 'metadata_cleaner_log', 'created_by', 'user', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->safeDropTable('metadata_cleaner_log');
    }
}
