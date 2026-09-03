<?php return array(
    'root' => array(
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'type' => 'akaunting-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'reference' => '16e33e9fe8720615334b9d796e1cf53cd5019998',
        'name' => 'payzum/module-payzum',
        'dev' => true,
    ),
    'versions' => array(
        'omnipay/common' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'payzum/module-payzum' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'type' => 'akaunting-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'reference' => '16e33e9fe8720615334b9d796e1cf53cd5019998',
            'dev_requirement' => false,
        ),
        'payzum/omnipay-payzum' => array(
            'pretty_version' => 'v0.1.1',
            'version' => '0.1.1.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../payzum/omnipay-payzum',
            'aliases' => array(),
            'reference' => '19a259aa1a46513731f6d590202554c29e61da0a',
            'dev_requirement' => false,
        ),
        'payzum/payzum-php' => array(
            'pretty_version' => 'v0.1.0',
            'version' => '0.1.0.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../payzum/payzum-php',
            'aliases' => array(),
            'reference' => 'c646c948883da1aad21d43d4692791604bc09bbc',
            'dev_requirement' => false,
        ),
    ),
);
