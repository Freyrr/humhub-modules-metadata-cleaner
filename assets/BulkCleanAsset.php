<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\assets;

use humhub\components\assets\AssetBundle;

/**
 * Script of the "Existing files" tab of the module settings.
 */
class BulkCleanAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = '@metadata-cleaner/resources';

    /**
     * @inheritdoc
     */
    public $js = [
        'js/humhub.metadataCleaner.bulk.js',
    ];
}
