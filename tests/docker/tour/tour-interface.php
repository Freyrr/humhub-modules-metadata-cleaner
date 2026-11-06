<?php

/**
 * Custom "Overview" tour (the one that starts on the dashboard at the first
 * login): the core tour plus one slide that asks the new user for an action.
 *
 * Loaded through the `tour` module setting `tourConfigFiles` (HumHub 1.18+),
 * set in tests/docker-compose.yml with
 * HUMHUB_CONFIG__MODULES__TOUR__TOUR_CONFIG_FILES. No core file is changed.
 *
 * The example action is "upload a profile photo". To ask for something else,
 * change $actionUrl, $isActionDone and the texts below.
 */

use humhub\modules\tour\TourConfig;
use humhub\modules\user\models\User;
use humhub\widgets\bootstrap\Link;
use yii\helpers\Url;

$config = require Yii::getAlias('@tour/config/tour-interface.php');

/** @var User|null $user */
$user = Yii::$app->user->getIdentity();

// Page where the user makes the change: the profile page, where the photo
// is uploaded by clicking on it.
$actionUrl = $user !== null ? $user->createUrl('/user/profile/home') : Url::home();

// The slide is shown only while the change is still missing.
$isActionDone = $user !== null && $user->getImage()->exists();

// HumHub has no message category for files outside a module, so the texts
// are chosen here by language.
$italian = str_starts_with(Yii::$app->language, 'it');
$title = $italian
    ? '<strong>Prima di iniziare</strong>'
    : '<strong>Before you start</strong>';
$description = $italian
    ? 'Carica una foto nel tuo profilo, così i colleghi ti riconoscono.'
    : 'Please upload a profile photo, so your colleagues can recognize you.';
$button = $italian
    ? '<strong>Vai al profilo</strong>'
    : '<strong>Go to my profile</strong>';

if (!$isActionDone) {
    $step = [
        // Highlights the account menu, where the profile settings are.
        'element' => '.dropdown.account',
        'popover' => [
            'title' => $title,
            'description' => $description
                . '<br><br>'
                . Link::to($button, $actionUrl)->encodeLabel(false)
                . '<br><br>',
        ],
    ];

    // Second slide, right after "This is your dashboard".
    array_splice($config[TourConfig::KEY_DRIVER_JS]['steps'], 1, 0, [$step]);
}

return $config;
