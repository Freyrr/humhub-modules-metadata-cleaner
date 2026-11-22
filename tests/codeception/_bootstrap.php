<?php

/**
 * @license AGPL-3.0-or-later
 */

/*
 * Bootstrap of the HumHub test suites (unit, functional). It loads the
 * default test bootstrap of the HumHub core.
 *
 * The HumHub installation is found through the HUMHUB_PATH environment
 * variable, then the "humhub_root" entry of tests/config/test.php, and
 * otherwise the module is expected in protected/modules/metadata-cleaner.
 *
 * The cleaner tests do not need HumHub and do not use this file: see
 * tests/codeception.cleaners.yml.
 */

$testRoot = dirname(__DIR__);

\Codeception\Configuration::append(['test_root' => $testRoot]);
codecept_debug('Module root: ' . $testRoot);

$humhubPath = getenv('HUMHUB_PATH');
if ($humhubPath === false) {
    $moduleConfig = require $testRoot . '/config/test.php';
    $humhubPath = $moduleConfig['humhub_root'] ?? dirname(__DIR__, 5);
}

\Codeception\Configuration::append(['humhub_root' => $humhubPath]);
codecept_debug('HumHub Root: ' . $humhubPath);

// Load the test configuration (/config/test.php or /config/env/<environment>/test.php)
$globalConfig = require $humhubPath . '/protected/humhub/tests/codeception/_loadConfig.php';

// Load the default test bootstrap (initializes Yii)
require $globalConfig['humhub_root'] . '/protected/humhub/tests/codeception/_bootstrap.php';
