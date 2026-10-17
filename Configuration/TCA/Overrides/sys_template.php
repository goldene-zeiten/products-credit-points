<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    ExtensionManagementUtility::addStaticFile(
        'products_credit_points',
        'Configuration/TypoScript',
        'Products Credit Points'
    );
})();
