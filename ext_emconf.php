<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Products Credit Points',
    'description' => 'Credit points loyalty programme for the Products shop system',
    'category' => 'plugin',
    'author' => 'Markus Hofmann',
    'author_email' => 'markus.hofmann@goldene-zeiten.de',
    'state' => 'alpha',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'products_core' => '1.0.0-1.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
