<?php
return [
    'db' => [
        'connection' => [
            'default' => [
                'host' => 'db-primary.internal',
                'dbname' => 'magento',
                'engine' => 'innodb',
                'active' => '1',
                'isolation_level' => 'READ COMMITTED',
            ],
            'sales' => [
                'host' => 'db-sales.internal',
                'dbname' => 'magento_sales',
                'engine' => 'innodb',
                'active' => '1',
            ],
            'checkout' => [
                'host' => 'db-checkout.internal',
                'dbname' => 'magento_checkout',
                'engine' => 'innodb',
                'active' => '1',
                'isolation_level' => 'REPEATABLE READ',
            ],
        ],
    ],
];
