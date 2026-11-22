<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\Migration;

/**
 * Adds the content hash used to link a log row written at upload time to the
 * stored file, which may arrive in a later request.
 */
class m261104_120000_content_hash extends Migration
{
    public function safeUp()
    {
        $this->safeAddColumn('metadata_cleaner_log', 'content_hash', $this->string(40)->null());
        $this->safeCreateIndex('idx_metadata_cleaner_log_content_hash', 'metadata_cleaner_log', 'content_hash');
    }

    public function safeDown()
    {
        $this->safeDropColumn('metadata_cleaner_log', 'content_hash');
    }
}
