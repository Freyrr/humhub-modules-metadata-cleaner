<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\functional;

use metadataCleaner\FunctionalTester;

/**
 * The settings, log and cleaning job are for system administrators only.
 */
class AdminAccessCest
{
    public function testAdminCanOpenTheSettings(FunctionalTester $I): void
    {
        $I->amAdmin();
        $I->amOnRoute('/metadata-cleaner/admin');
        $I->seeResponseCodeIs(200);
        $I->see('Existing files');
    }

    public function testUserCannotOpenTheSettings(FunctionalTester $I): void
    {
        $I->amUser2();
        $I->amOnRoute('/metadata-cleaner/admin');
        $I->seeResponseCodeIs(403);
    }

    public function testUserCannotStartTheCleaningJob(FunctionalTester $I): void
    {
        $I->amUser2();
        $I->sendAjaxPostRequest('/index-test.php?r=metadata-cleaner/admin/bulk-clean', ['op' => 'start']);
        $I->seeResponseCodeIs(403);
    }
}
