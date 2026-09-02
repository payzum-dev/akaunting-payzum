<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

use Omnipay\Payzum\Gateway;
use Omnipay\Tests\GatewayTestCase;

class GatewayTest extends GatewayTestCase
{
    /** @var Gateway */
    protected $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new Gateway($this->getHttpClient(), $this->getHttpRequest());
        $this->gateway->setApiKey(TestKeys::API_KEY);
    }

    public function testName(): void
    {
        self::assertSame('Payzum', $this->gateway->getName());
    }

    public function testSupportsExpectedOperations(): void
    {
        self::assertTrue($this->gateway->supportsPurchase());
        self::assertTrue($this->gateway->supportsCompletePurchase());
        self::assertTrue($this->gateway->supportsFetchTransaction());
        self::assertTrue($this->gateway->supportsAcceptNotification());

        // The Payzum merchant API has no refund/void/card surface; the driver
        // must not advertise capabilities that cannot work.
        self::assertFalse($this->gateway->supportsRefund());
        self::assertFalse($this->gateway->supportsVoid());
        self::assertFalse($this->gateway->supportsAuthorize());
        self::assertFalse($this->gateway->supportsCreateCard());
    }

    public function testWebhookSecretRoundTrips(): void
    {
        $this->gateway->setWebhookSecret('whsec-test');
        self::assertSame('whsec-test', $this->gateway->getWebhookSecret());
    }

    public function testPayCurrencyDefaultsToBuyerChoice(): void
    {
        self::assertSame('all', $this->gateway->getPayCurrency());
    }
}
