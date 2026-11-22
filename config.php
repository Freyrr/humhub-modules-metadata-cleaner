<?php

/**
 * @license AGPL-3.0-or-later
 */

use humhub\commands\CronController;
use humhub\modules\file\models\File;
use humhub\modules\file\models\FileUpload;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\Module;

return [
    'id' => 'metadata-cleaner',
    'class' => Module::class,
    'namespace' => 'humhub\modules\metadataCleaner',
    'events' => [
        [
            'class' => FileUpload::class,
            'event' => FileUpload::EVENT_AFTER_VALIDATE,
            'callback' => [Events::class, 'onFileUploadAfterValidate'],
        ],
        [
            'class' => File::class,
            'event' => File::EVENT_AFTER_NEW_STORED_FILE,
            'callback' => [Events::class, 'onAfterNewStoredFile'],
        ],
        [
            'class' => CronController::class,
            'event' => CronController::EVENT_ON_DAILY_RUN,
            'callback' => [Events::class, 'onCronDailyRun'],
        ],
    ],
    'consoleControllerMap' => [
        'metadata-cleaner' => 'humhub\modules\metadataCleaner\commands\CleanController',
    ],
];
