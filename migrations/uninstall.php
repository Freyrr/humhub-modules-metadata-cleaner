<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\components\Migration;

class uninstall extends Migration
{
    public function up()
    {
        $this->safeDropTable('metadata_cleaner_log');
        $this->safeDropTable('metadata_cleaner_stats');
    }

    public function down()
    {
        echo "uninstall does not support migration down.\n";
        return false;
    }
}
