<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

/** Shared fixtures. The SDK enforces a minimum API-key length of 32. */
final class TestKeys
{
    public const API_KEY = 'test-api-key-0123456789abcdef0123456789';

    public const WEBHOOK_SECRET = 'payzum_test_webhook_secret_do_not_use_in_production';
}
