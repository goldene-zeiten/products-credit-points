<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    $newColumnsArray = [
        'credit_points' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_product.credit_points',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 0,
            ],
        ],
    ];

    ExtensionManagementUtility::addTCAcolumns('tx_products_domain_model_product', $newColumnsArray);

    // Place it at the end of the product's "marketing" tab.
    ExtensionManagementUtility::addToAllTCAtypes(
        'tx_products_domain_model_product',
        'credit_points',
        '',
        'before:--div--;LLL:EXT:products_core/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_product.tab_media',
    );
})();
