<?php

return [

    'name'              => 'Payzum',
    'description'       => 'Accept crypto and stablecoin payments (USDC, USDT and more) with Payzum, non-custodial: funds settle directly to your own wallet',

    'form' => [
        'api_key'        => 'API Key',
        'webhook_secret' => 'Webhook Secret',
        'mode'           => 'Mode',
        'pay_currency'   => 'Pay Currency (optional, e.g. usdc — leave empty to let the buyer choose)',
        'customer'       => 'Show to Customer',
        'order'          => 'Order',
        'debug'          => 'Debug',
    ],

    'payment' => [
        'pending'       => 'Payment is pending',
        'not_added'     => 'Payment not added!',
        'processing'    => 'Thank you! Your payment is being confirmed on-chain. The invoice will be marked as paid once the payment is confirmed.',
    ],

    'test_mode'         => 'Warning: The payment gateway is in \'Sandbox Mode\'. Your account will not be charged.',

];
