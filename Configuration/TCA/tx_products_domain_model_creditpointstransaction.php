<?php

declare(strict_types=1);

use GoldeneZeiten\Products\CreditPoints\Domain\Enum\CreditPointsTransactionType;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction',
        'label' => 'frontend_user',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'iconfile' => 'EXT:products_core/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'frontend_user, order_uid, points, type, created'],
    ],
    'columns' => [
        'frontend_user' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.frontend_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'fe_users',
                'minitems' => 1,
                'maxitems' => 1,
            ],
        ],
        'order_uid' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.order_uid',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_products_domain_model_order',
                'default' => 0,
            ],
        ],
        'points' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.points',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'required' => true,
            ],
        ],
        'type' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.type.earn',
                        'value' => CreditPointsTransactionType::EARN->value,
                    ],
                    [
                        'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.type.redeem',
                        'value' => CreditPointsTransactionType::REDEEM->value,
                    ],
                    [
                        'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.type.adjustment',
                        'value' => CreditPointsTransactionType::ADJUSTMENT->value,
                    ],
                ],
            ],
        ],
        'created' => [
            'label' => 'LLL:EXT:products_credit_points/Resources/Private/Language/locallang_tca.xlf:tx_products_domain_model_creditpointstransaction.created',
            'config' => [
                'type' => 'datetime',
                'size' => 13,
                'eval' => 'datetime',
                'default' => 0,
            ],
        ],
    ],
];
