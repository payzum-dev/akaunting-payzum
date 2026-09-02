<?php

namespace Modules\Payzum\Listeners;

use App\Events\Module\PaymentMethodShowing as Event;

class ShowAsPaymentMethod
{
    /**
     * Handle the event.
     *
     * @param  Event $event
     * @return void
     */
    public function handle(Event $event)
    {
        $method = setting('payzum');

        $method['code'] = 'payzum';

        $event->modules->payment_methods[] = $method;
    }
}
