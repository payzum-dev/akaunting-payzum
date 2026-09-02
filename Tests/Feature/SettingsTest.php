<?php

namespace Modules\Payzum\Tests\Feature;

use Tests\Feature\FeatureTestCase;

class SettingsTest extends FeatureTestCase
{
    public function testItShouldSeePayzumSettingsUpdatePage()
    {
        $this->loginAs()
            ->get(route('settings.module.edit', ['alias' => 'payzum']))
            ->assertOk()
            ->assertSeeText(trans('payzum::general.name'))
            ->assertSeeText(trans('payzum::general.description'));
    }

    public function testItShouldUpdatePayzumSettings()
    {
        $this->loginAs()
            ->patch(route('settings.module.edit', ['alias' => 'payzum']), $this->getRequest())
            ->assertOk();

        $this->assertFlashLevel('success');
    }

    public function getRequest()
    {
        return [
            'name' => $this->faker->name,
            'api_key' => 'pz_test_' . $this->faker->md5,
            'webhook_secret' => $this->faker->sha256,
            'mode' => 'sandbox',
            'customer' => 1,
            'debug' => 1,
            'order' => 1,
        ];
    }
}
